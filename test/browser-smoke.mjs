import assert from "node:assert/strict";
import { chromium } from "/Users/sushil-airm2/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs";

const browser = await chromium.launch({ headless: true });
const problems = [];

for (const view of [
  { name: "desktop", width: 1440, height: 1000 },
  { name: "mobile", width: 390, height: 844 },
]) {
  const page = await browser.newPage({ viewport: { width: view.width, height: view.height } });
  page.on("pageerror", error => problems.push(`${view.name}: ${error.message}`));
  await page.goto("http://127.0.0.1:8772/", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(1800);
  const text = await page.locator("body").innerText();
  assert.match(text, /Search & Book Online/i);
  assert.doesNotMatch(text, /Your booking/i);
  await page.screenshot({ path: `/tmp/kosipark-v2-${view.name}.png`, fullPage: true });

  await page.goto("http://127.0.0.1:8772/availability", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(1800);
  const calendarText = await page.locator("body").innerText();
  assert.match(calendarText, /Availability/i);
  await page.screenshot({ path: `/tmp/kosipark-v2-availability-${view.name}.png`, fullPage: true });
  await page.close();
}

await browser.close();
assert.deepEqual(problems, []);
console.log("Kosipark V2 desktop and mobile browser checks passed.");
