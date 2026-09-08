/** Sold-out searches recover inside the normal booking flow. */
import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";

const PORT = 8821;
const BASE = `http://localhost:${PORT}`;
const SHOTS = process.argv.includes("--shots");
const server = spawn("python3", ["dev-server.py", String(PORT)], {
  cwd: new URL("..", import.meta.url).pathname,
  stdio: "ignore",
});
process.on("exit", () => server.kill());

let failures = 0;
const ok = (condition, label, detail = "") => {
  if (!condition) failures++;
  console.log(`${condition ? "PASS" : "FAIL"}  ${label}${detail ? "  " + detail : ""}`);
};

await sleep(900);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on("pageerror", error => errors.push(error.message));

// A one-night winter Saturday fails the mock's genuine minimum-stay rules for
// every type, while nearby Sunday arrivals remain bookable.
await page.goto(`${BASE}/book?arrival=2026-07-04&departure=2026-07-05&adults=2`, { waitUntil: "networkidle" });
await page.waitForFunction(() => document.body.innerText.includes("Those exact dates are unavailable"));
await page.waitForFunction(() => document.body.innerText.toLowerCase().includes("1 day later"));

let text = await page.locator("body").innerText();
ok(text.includes("Those exact dates are unavailable"), "sold-out search explains the dead end");
ok(text.includes("We've kept your 1-night stay and 2 guests"), "party and stay length are retained");
ok(text.toLowerCase().includes("1 day later"), "closest live alternative is shown");
ok(/\$\d+ total/.test(text), "alternative carries a quoted stay total");
ok(await page.locator("article").count() === 0, "unavailable room cards do not overwhelm recovery");
if (SHOTS) await page.screenshot({ path: "/tmp/kosipark-recovery-options.png", fullPage: false });

await page.getByRole("button", { name: "Check alternate dates" }).click();
text = await page.locator("body").innerText();
ok(text.includes("Nearby arrival dates for the same length of stay"), "alternate-date panel opens inline");
ok(/Available|Only [12] left/.test(text), "calendar uses real availability labels");
ok(text.includes("Unavailable"), "calendar also labels unavailable dates");
if (SHOTS) await page.screenshot({ path: "/tmp/kosipark-recovery-calendar.png", fullPage: false });

const firstChoice = page.locator('button').filter({ hasText: /1 day later/i }).first();
await firstChoice.click();
await page.waitForURL(/arrival=2026-07-05/);
await page.waitForFunction(() => document.querySelectorAll("article").length > 0);
ok(page.url().includes("departure=2026-07-06"), "choosing an alternate preserves the stay length", page.url());
ok(page.url().includes("adults=2"), "choosing an alternate preserves guests", page.url());
ok(errors.length === 0, "recovery flow has no uncaught browser errors", errors.join(" | "));

await browser.close();
server.kill();
console.log(failures === 0 ? "\nAll unavailable-date recovery checks passed." : `\n${failures} recovery check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
