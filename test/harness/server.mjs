/**
 * Boots the fake GuestPoint upstream and the real PHP proxy for one test run.
 *
 * The proxy reads its configuration from public_html/api/gp/config.php, which
 * is gitignored and on a real machine holds live GuestPoint credentials. The
 * harness therefore moves any existing file aside and restores it on exit,
 * including on crash and on SIGINT.
 */
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";
import { mkdtempSync, rmSync, writeFileSync, readFileSync, existsSync, renameSync, mkdirSync, readdirSync, unlinkSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const ROOT = new URL("../..", import.meta.url).pathname.replace(/\/$/, "");
const HARNESS = join(ROOT, "test", "harness");
const LIVE_CONFIG = join(ROOT, "public_html", "api", "gp", "config.php");
const SAVED_CONFIG = LIVE_CONFIG + ".harness-saved";

export async function startHarness({ upstreamPort = 9911, proxyPort = 9912 } = {}) {
  const dir = mkdtempSync(join(tmpdir(), "kosipark-harness-"));
  const cacheDir = join(dir, "cache");
  mkdirSync(cacheDir, { recursive: true });
  writeFileSync(join(dir, "mode.txt"), "");
  writeFileSync(join(dir, "state.json"), "{}");

  if (existsSync(LIVE_CONFIG)) {
    if (existsSync(SAVED_CONFIG)) {
      throw new Error(`${SAVED_CONFIG} already exists — a previous harness run did not clean up. Restore it by hand before rerunning.`);
    }
    renameSync(LIVE_CONFIG, SAVED_CONFIG);
  }
  const template = readFileSync(join(HARNESS, "config.harness.php"), "utf8");
  writeFileSync(LIVE_CONFIG, template.replace(/\{\{PORT\}\}/g, String(upstreamPort)).replace(/\{\{CACHE\}\}/g, cacheDir));

  // The fake needs workers: one scenario deliberately hangs a request past the
  // proxy's upstream timeout, and a single-threaded server would block every
  // test that follows it.
  const env = { ...process.env, HARNESS_DIR: dir, PHP_CLI_SERVER_WORKERS: "8" };
  const upstream = spawn("php", ["-S", `127.0.0.1:${upstreamPort}`, join(HARNESS, "fake-guestpoint.php")], { cwd: HARNESS, env, stdio: "ignore" });
  const proxy = spawn("php", ["-S", `127.0.0.1:${proxyPort}`, "-t", join(ROOT, "public_html"), join(HARNESS, "router.php")], { cwd: ROOT, env, stdio: "ignore" });

  let stopped = false;
  const stop = () => {
    if (stopped) return;
    stopped = true;
    upstream.kill("SIGKILL");
    proxy.kill("SIGKILL");
    try { rmSync(LIVE_CONFIG, { force: true }); } catch { /* already gone */ }
    if (existsSync(SAVED_CONFIG)) renameSync(SAVED_CONFIG, LIVE_CONFIG);
    try { rmSync(dir, { recursive: true, force: true }); } catch { /* best effort */ }
  };
  process.on("exit", stop);
  process.on("SIGINT", () => { stop(); process.exit(130); });
  process.on("uncaughtException", error => { stop(); throw error; });

  // Wait for both servers rather than guessing at a sleep. The proxy answers
  // an unauthenticated lookup with 403, which is a perfectly good liveness
  // signal — we only care that something is listening and running PHP.
  const base = `http://127.0.0.1:${proxyPort}`;
  const responds = url => fetch(url, { method: "POST", body: "{}" }).then(() => true).catch(() => false);
  let ready = false;
  for (let attempt = 0; attempt < 80 && !ready; attempt++) {
    const [upstreamUp, proxyUp] = await Promise.all([
      responds(`http://127.0.0.1:${upstreamPort}/pms/token`),
      responds(`${base}/api/gp/properties/self/portal/lookup`),
    ]);
    ready = upstreamUp && proxyUp;
    if (!ready) await sleep(150);
  }
  if (!ready) { stop(); throw new Error(`harness servers did not start on ${upstreamPort}/${proxyPort} — is php on PATH, or are the ports in use?`); }

  const api = `${base}/api/gp/properties/self`;
  const readState = () => JSON.parse(readFileSync(join(dir, "state.json"), "utf8"));

  return {
    dir, base, api, stop, readState,
    // Every portal write counts against the proxy's per-IP rate limit, and the
    // whole suite shares one IP. Without this a long enough run starts failing
    // with 429 on a call that has nothing to do with the test.
    clearRateLimit: () => {
      for (const name of readdirSync(cacheDir)) {
        if (name.startsWith("rl_")) { try { unlinkSync(join(cacheDir, name)); } catch { /* raced */ } }
      }
    },
    setState: next => writeFileSync(join(dir, "state.json"), JSON.stringify(next, null, 1)),
    patchState: patch => {
      const next = { ...readState(), ...patch };
      writeFileSync(join(dir, "state.json"), JSON.stringify(next, null, 1));
    },
    setMode: mode => writeFileSync(join(dir, "mode.txt"), mode ?? ""),
    callLog: () => { try { return readFileSync(join(dir, "calls.log"), "utf8").trim().split("\n").filter(Boolean); } catch { return []; } },
    resetCallLog: () => writeFileSync(join(dir, "calls.log"), ""),
    async post(path, body) {
      const response = await fetch(`${api}${path}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      let payload = null;
      try { payload = await response.json(); } catch { payload = null; }
      return { status: response.status, body: payload };
    },
    async portalToken(confNum = "R1001", surname = "Smith") {
      const { status, body } = await this.post("/portal/lookup", { ConfNum: confNum, Surname: surname });
      const root = body?.data ?? body?.Data ?? body;
      const token = root?.PortalToken ?? null;
      if (!token) {
        // Carry the proxy's own answer up; "no token" on its own tells the
        // reader nothing, and a 429 from the shared per-IP limit looks
        // identical to a genuine lookup failure.
        const reason = body?.Error?.Message ?? JSON.stringify(body)?.slice(0, 200);
        throw new Error(`portal lookup for ${confNum}/${surname} returned ${status}: ${reason}`);
      }
      return token;
    },
  };
}
