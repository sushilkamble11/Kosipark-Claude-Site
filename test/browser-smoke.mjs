import assert from "node:assert/strict";
import { chromium } from "/Users/sushil-airm2/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs";

const browser = await chromium.launch({ headless: true });
const problems = [];

for (const view of [
  { name: "desktop", width: 1440, height: 1000 },
  { name: "mobile", width: 390, height: 844 },
]) {
  const page = await browser.newPage({ viewport: { width: view.width, height: view.height } });
  // The GuestPoint development fixture is dated September 2026. Pin the
  // browser clock so CTA/CTD and sold-out checkout behaviour stay testable
  // after those fixture dates have passed in real time.
  await page.addInitScript(() => {
    const NativeDate = Date;
    const fixed = new NativeDate("2026-09-09T00:00:00+10:00").getTime();
    window.Date = class extends NativeDate {
      constructor(...args) { super(...(args.length ? args : [fixed])); }
      static now() { return fixed; }
    };
  });
  page.on("pageerror", error => problems.push(`${view.name}: ${error.message}`));
  await page.goto("http://127.0.0.1:8772/", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(1800);
  const text = await page.locator("body").innerText();
  assert.match(text, /Search & Book Online/i);
  assert.doesNotMatch(text, /Your booking/i);
  await page.screenshot({ path: `/tmp/kosipark-v2-${view.name}.png`, fullPage: true });

  await page.goto("http://127.0.0.1:8772/availability", { waitUntil: "domcontentloaded" });
  await page.getByText(/Live GuestPoint availability|GuestPoint HAR test data/, { exact: true }).waitFor({ timeout: 45000 });
  const calendarText = await page.locator("body").innerText();
  assert.match(calendarText, /Availability/i);
  assert.match(calendarText, /Single Room/i);
  assert.doesNotMatch(calendarText, /Twin Room|King Room|Queen/i);
  assert.doesNotMatch(calendarText, /Why\?|Restricted — tap/i);
  assert.match(calendarText, /3 left/i);
  await page.screenshot({ path: `/tmp/kosipark-v2-availability-clean-${view.name}.png`, fullPage: true });
  const buttonForDay = async day => {
    const buttons = page.locator("button");
    for (let i = 0; i < await buttons.count(); i += 1) {
      const button = buttons.nth(i);
      const text = (await button.innerText()).replace(/\s+/g, " ").trim();
      if (text === String(day) || text.startsWith(`${day} `)) return button;
    }
    throw new Error(`${view.name}: calendar day ${day} not found`);
  };

  // The live GuestPoint test category marks Saturday 12 September closed to
  // arrival, but it stays visually available until the guest selects it.
  await (await buttonForDay(12)).click();
  assert.match(await page.locator("body").innerText(), /No Saturday check-ins in winter/i);
  await (await buttonForDay(13)).click();
  assert.doesNotMatch(await page.locator("body").innerText(), /No Saturday check-ins in winter/i);
  await page.getByRole("button", { name: "Clear" }).click();

  // Selecting Friday keeps Saturday usable as a night stayed. Only an attempt
  // to leave on Saturday explains the winter departure rule.
  await (await buttonForDay(11)).click();
  await (await buttonForDay(12)).click();
  assert.match(await page.locator("body").innerText(), /No Saturday check-outs in winter/i);
  await page.waitForTimeout(5400);
  assert.doesNotMatch(await page.locator("body").innerText(), /No Saturday check-outs in winter/i);
  await (await buttonForDay(13)).click();
  assert.match(await page.locator("body").innerText(), /11 Sept → 13 Sept · 2 nights/i);
  await page.getByRole("button", { name: "Clear" }).click();

  assert.match((await (await buttonForDay(16)).innerText()).replace(/\s+/g, " "), /Sold out Checkout only/i);
  await (await buttonForDay(16)).click();
  assert.match(await page.locator("body").innerText(), /Sold out for this night/i);

  // Inventory is zero on 16 September. It may be used as the checkout
  // morning, but a stay may not cross it as a night occupied.
  await (await buttonForDay(14)).click();
  assert.doesNotMatch(await page.locator("body").innerText(), /Sold out for this night/i);
  assert.match(await (await buttonForDay(16)).innerText(), /Checkout OK/i);
  await (await buttonForDay(16)).click();
  await page.getByText(/\$398 total/, { exact: false }).waitFor({ timeout: 30000 });
  let selectedText = await page.locator("body").innerText();
  assert.match(selectedText, /14 Sept → 16 Sept · 2 nights/i);
  assert.match(selectedText, /\$398 total · Single Room · 3 left/i);

  await page.getByRole("button", { name: "Clear" }).click();
  await (await buttonForDay(15)).click();
  await (await buttonForDay(17)).click();
  selectedText = await page.locator("body").innerText();
  assert.match(selectedText, /unavailable on Wed, 16 Sept/i);
  await page.screenshot({ path: `/tmp/kosipark-v2-availability-${view.name}.png`, fullPage: true });
  await page.close();
}

await browser.close();
assert.deepEqual(problems, []);
console.log("Kosipark V2 desktop and mobile browser checks passed.");
