/**
 * Browser smoke test. Serves the site through dev-server.py (which applies the
 * same routes as .htaccess) and walks every URL a guest can reach, checking
 * that the page renders, the shared components mount, no console errors fire,
 * and the GuestPoint photo pipeline fills the slots it should.
 *
 *   node test/smoke.mjs            # headless, prints a pass/fail table
 *   node test/smoke.mjs --shots    # also writes PNGs to test/shots/
 */
import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { mkdirSync } from "node:fs";
import { setTimeout as sleep } from "node:timers/promises";

const PORT = 8731;
const BASE = `http://localhost:${PORT}`;
const SHOTS = process.argv.includes("--shots");

const ROUTES = [
  { url: "/",                              needs: ["Stay in the Heart of Kosciuszko"] },
  { url: "/accommodation",                 needs: ["Three Bedroom Chalet", "Cedar Cabin"] },
  { url: "/accommodation/cedar-cabin",     needs: ["Cedar Cabin"] },
  { url: "/accommodation/unpowered-site",  needs: ["Unpowered"] },
  { url: "/book?arrival=2026-10-02&departure=2026-10-05&adults=2", needs: ["night"] },
  { url: "/book/calendar?room=cedar-cabin",needs: ["Cedar Cabin"] },
  { url: "/book/checkout",                 needs: [] },
  { url: "/manage",                        needs: [] },
  { url: "/terms",                         needs: ["Terms"] },
  { url: "/attractions",                   needs: [] },
  { url: "/gallery",                       needs: [] },
  { url: "/contact",                       needs: ["6456 2224"] },
  { url: "/no-such-page",                  needs: ["can't find that page"], expectStatus: 404 },
];

// Noise that is not a defect in this sandbox: no PHP (the proxy 503s) and no
// outbound network (Google Fonts cannot resolve). Everything else must be clean.
const IGNORE = [
  /favicon/i,
  /Booking service is not configured/i,
  /\/api\/gp/,
  /fonts\.(googleapis|gstatic)\.com/,
  /ERR_TUNNEL_CONNECTION_FAILED/,
  /ERR_NAME_NOT_RESOLVED/,
  // SiteNav's weather pill. A third-party call from the guest's browser on
  // every page, but it fails soft (the pill just stays blank) and needs no key.
  /api\.open-meteo\.com/,
];

const server = spawn("python3", ["dev-server.py", String(PORT)], {
  cwd: new URL("..", import.meta.url).pathname, stdio: ["ignore", "ignore", "ignore"],
});
process.on("exit", () => server.kill());

let failures = 0;
const line = (ok, label, extra = "") => {
  if (!ok) failures++;
  console.log(`${ok ? "PASS" : "FAIL"}  ${label}${extra ? "  " + extra : ""}`);
};

await sleep(900);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
if (SHOTS) mkdirSync(new URL("shots/", import.meta.url), { recursive: true });

for (const route of ROUTES) {
  const errors = [];
  // A bare "Failed to load resource" console line does not say WHICH resource,
  // so failed requests are captured from the network side, with their URL.
  const onErr = m => {
    const t = m.text();
    if (m.type() === "error" && /Failed to load resource/.test(t)) return; // covered below
    if (m.type() === "error" && !IGNORE.some(r => r.test(t))) errors.push(t);
  };
  const onFailed = req => {
    const u = req.url();
    if (!IGNORE.some(r => r.test(u))) errors.push("request failed: " + u);
  };
  const onResponse = r => {
    if (r.status() >= 400 && !IGNORE.some(x => x.test(r.url()))) {
      errors.push(`HTTP ${r.status()}: ${r.url()}`);
    }
  };
  page.on("console", onErr);
  page.on("requestfailed", onFailed);
  page.on("response", onResponse);
  page.on("pageerror", e => errors.push("uncaught: " + e.message));

  const res = await page.goto(BASE + route.url, { waitUntil: "networkidle" });
  await sleep(400);   // components mount async

  const status = res.status();
  if (route.expectStatus) line(status === route.expectStatus, `${route.url} → ${route.expectStatus}`, `got ${status}`);
  else line(status === 200, `${route.url} loads`, `HTTP ${status}`);

  const text = await page.evaluate(() => document.body.innerText);
  for (const need of route.needs) {
    line(text.includes(need), `${route.url} shows "${need}"`);
  }

  // The shared nav and footer are dc components — if the loader broke under
  // <base href="/">, they are the first thing to vanish.
  if (status === 200 && !route.expectStatus) {
    const nav = await page.locator("header, nav").count();
    line(nav > 0, `${route.url} nav mounted`);
  }

  const real = route.expectStatus === 404
    ? errors.filter(e => !e.includes(route.url)) : errors;
  line(real.length === 0, `${route.url} console clean`, real.slice(0, 3).join(" | "));

  if (SHOTS) {
    await page.screenshot({
      path: new URL(`shots/${route.url.replace(/[^a-z0-9]+/gi, "_") || "home"}.png`, import.meta.url).pathname,
      fullPage: false,
    });
  }
  page.removeListener("console", onErr);
  page.removeListener("requestfailed", onFailed);
  page.removeListener("response", onResponse);
}

// --- photo pipeline --------------------------------------------------------
await page.goto(BASE + "/", { waitUntil: "networkidle" });
await sleep(1200);
const home = await page.evaluate(() => {
  const slots = [...document.querySelectorAll("image-slot")];
  const wired = slots.filter(s => s.hasAttribute("gp-room") || s.hasAttribute("gp-property"));
  return {
    total: slots.length,
    wired: wired.length,
    filled: slots.filter(s => (s.getAttribute("src") || "").length > 0).length,
    empty: slots.filter(s => !(s.getAttribute("src") || "").length).length,
    // A wired slot must show the API's photo, not a fallback panel.
    wiredFromApi: wired.filter(s => !s.hasAttribute("data-kosipark-placeholder")
                                    && (s.getAttribute("src") || "").length > 0).length,
    panels: slots.filter(s => s.hasAttribute("data-kosipark-placeholder")).length,
    unlabelled: slots.filter(s => !(s.getAttribute("alt") || "").length).length,
  };
});
line(home.wiredFromApi === home.wired, "home: every API-wired slot shows its GuestPoint photo", JSON.stringify(home));
line(home.empty === 0, "home: no slot is left as a grey hole", `${home.empty} empty`);
line(home.panels > 0, "home: unmapped slots get a designed panel", `${home.panels} panels`);
line(home.unlabelled === 0, "home: every slot has alt text", `${home.unlabelled} missing`);

await page.goto(BASE + "/accommodation/cedar-cabin", { waitUntil: "networkidle" });
await sleep(1200);
const room = await page.evaluate(() => {
  const slots = [...document.querySelectorAll("image-slot")];
  return {
    ids: slots.map(s => s.id),
    filled: slots.filter(s => (s.getAttribute("src") || "").length > 0).length,
    captions: slots.map(s => s.getAttribute("alt")).filter(Boolean),
  };
});
line(room.filled >= 3, "room page: photos resolved by id convention", JSON.stringify(room.ids));
line(room.ids.every(id => /^r-cedar-cabin-[123]$/.test(id)),
     "room page: slot ids match the requested room", JSON.stringify(room.ids));
line(room.captions.length >= 3, "room page: GuestPoint captions become alt text", room.captions[0] || "");

// --- fragment links still scroll under <base href="/"> ---------------------
await page.goto(BASE + "/", { waitUntil: "networkidle" });
await sleep(600);
const before = await page.evaluate(() => window.scrollY);
await page.evaluate(() => {
  const a = document.querySelector('a[href="#book"]');
  if (a) a.click();
});
await sleep(700);
const after = await page.evaluate(() => ({ y: window.scrollY, path: location.pathname }));
line(after.y > before, "home: #book scrolls instead of navigating away", `y ${before} → ${after.y}`);
line(after.path === "/", "home: #book stays on the page", after.path);

// --- an internal link goes to a clean URL ---------------------------------
await page.goto(BASE + "/accommodation", { waitUntil: "networkidle" });
await sleep(500);
const href = await page.evaluate(() =>
  (document.querySelector('a[href^="/accommodation/"]') || {}).getAttribute?.("href"));
line(!!href && !href.includes(".dc.html"), "links use clean URLs", String(href));

await browser.close();
server.kill();
console.log(failures === 0 ? "\nAll checks passed." : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
