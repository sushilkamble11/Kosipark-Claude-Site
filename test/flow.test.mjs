/**
 * The guest's actual path, in a real browser: pick a stay, use "Add another
 * cabin or site", search again, pick a second, and check that BOTH are in the
 * booking and the cart badge says two.
 *
 * This is the bug the guest reported twice. Unit tests on the cart passed
 * while the page still lost the stay, because the loss happens between pages
 * — in the URL, the mount order and the nav's own copy of the cart — not
 * inside addToCart. So this drives the browser.
 *
 *   node test/flow.test.mjs
 */
import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";

const PORT = 8811;
const LIVE_BASE = (process.env.KOSIPARK_BASE_URL || "").replace(/\/$/, "");
const BASE = LIVE_BASE || `http://localhost:${PORT}`;
// The live header makes a best-effort weather request. Waiting for complete
// network silence would make a healthy page look hung when that provider is
// slow, so live checks wait for the page itself and then for its components.
const READY = LIVE_BASE ? "domcontentloaded" : "networkidle";
const server = LIVE_BASE ? null : spawn("python3", ["dev-server.py", String(PORT)], {
  cwd: new URL("..", import.meta.url).pathname, stdio: "ignore",
});
process.on("exit", () => { if (server) server.kill(); });

let failures = 0;
const ok = (c, label, extra = "") => { if (!c) failures++; console.log(`${c ? "PASS" : "FAIL"}  ${label}${extra ? "  " + extra : ""}`); };

await sleep(1000);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on("pageerror", e => { failures++; console.log("FAIL  uncaught: " + e.message); });

const cart = () => page.evaluate(() => JSON.parse(localStorage.getItem("kosipark-cart") || "[]"));

const search = "arrival=2026-10-02&departure=2026-10-05&adults=2";
await page.goto(`${BASE}/book?${search}`, { waitUntil: READY });
await sleep(1500);

// --- pick the first stay ---------------------------------------------------
const cardTitle = i => page.locator("article").nth(i).locator("h2").first().innerText().catch(() => "");
const selects = page.locator('button:has-text("Select")');
ok(await selects.count() > 1, "results list offers more than one option", String(await selects.count()));
const firstCardTitle = (await cardTitle(0)).trim();
await selects.first().click();
await page.waitForURL(/\/book\/checkout/, { timeout: 8000 }).catch(() => {});
await sleep(1800);
let c = await cart();
ok(c.length === 1, "first stay is in the cart", JSON.stringify(c.map(i => i.name)));
const firstName = c[0] && c[0].name;
const firstSlug = c[0] && c[0].roomTypeId;

// --- "Add another cabin or site" ------------------------------------------
const addAnother = page.locator('a:has-text("Add another")').first();
ok(await addAnother.count() > 0, "checkout offers 'Add another cabin or site'");
await addAnother.click();
await sleep(1200);

// The guest searches again for the same dates.
await page.goto(`${BASE}/book?${search}`, { waitUntil: READY });
await sleep(1500);

// --- pick a DIFFERENT second stay -----------------------------------------
const cards = page.locator("article");
let picked = null;
const n = await cards.count();
for (let i = 0; i < n; i++) {
  const card = cards.nth(i);
  const title = (await card.locator("h2").first().innerText().catch(() => "")).trim();
  // Compare against the CARD the guest clicked, not the name the cart chose
  // for it — those two disagree, which is its own problem.
  if (!title || title === firstCardTitle) continue;
  const btn = card.locator('button:has-text("Select")');
  if (await btn.count() === 0) continue;
  picked = title;
  await btn.first().click();
  break;
}
ok(!!picked, "a second, different option could be selected", String(picked));
await page.waitForURL(/\/book\/checkout/, { timeout: 8000 }).catch(() => {});
await sleep(2000);

c = await cart();
const url = page.url();
ok(!url.includes("room=" + firstSlug), "the second selection is a different room type", url.split("?")[1] || "");
ok(c.length === 2, "BOTH stays are in the cart", JSON.stringify(c.map(i => i.name)));

// --- what the page and the nav actually show ------------------------------
const text = await page.evaluate(() => document.body.innerText);
ok(firstCardTitle && text.includes(firstCardTitle), "the first stay is still named on the checkout page", String(firstCardTitle));
ok(picked && text.includes(picked), "the second stay is named on the checkout page", String(picked));
ok(/2 stays/.test(text), "the page says two stays", (text.match(/\d+ stays?/g) || []).join(", "));

// The nav badge is the guest's running count.
const badge = await page.evaluate(() => {
  const el = document.querySelector('[data-cart="1"] button');
  return el ? el.innerText.replace(/\s+/g, " ").trim() : "(no cart button)";
});
ok(/2/.test(badge), "the cart badge counts two", badge);

// --- the same room type can be booked twice --------------------------------
// A family may need two of the same cabin or site for the same dates. This is
// a deliberate second selection, not a double-click: it must add another unit.
await page.goto(`${BASE}/book?${search}`, { waitUntil: READY });
await sleep(1400);
const sameRoom = page.locator("article").nth(0);
const sameName = (await sameRoom.locator("h2").first().innerText()).trim();
await sameRoom.locator('button:has-text("Select")').first().click();
await page.waitForURL(/\/book\/checkout/, { timeout: 8000 }).catch(() => {});
await sleep(1800);
const afterSame = await cart();
ok(afterSame.length === 3, "a deliberate second unit of the same room type is added", JSON.stringify(afterSame.map(i => i.name)));
ok(afterSame.filter(i => i.name === sameName).length >= 2,
   "both units of the same room type stay in the booking", sameName);

// Reloading the checkout URL replays the same selection token. That must not
// create an accidental extra unit.
await page.reload({ waitUntil: READY });
await sleep(1200);
const afterReload = await cart();
ok(afterReload.length === 3, "refreshing checkout does not duplicate a unit", String(afterReload.length));

// --- one timer, not two ----------------------------------------------------
const finalText = await page.evaluate(() => document.body.innerText);
const timers = (finalText.match(/\d+:\d\d/g) || []);
ok(timers.length === 1, "one countdown is shown on the checkout screen", timers.join(", "));
ok(/(?:Refundable|Non-refundable|Cancellation terms)/.test(finalText),
   "each stay shows its cancellation status", (finalText.match(/(?:Refundable|Non-refundable|Cancellation terms)/g) || []).join(", "));

const removeButtons = page.locator('aside button:has-text("Remove")');
ok(await removeButtons.count() === afterReload.length,
   "every stay, including the first, has a remove button", String(await removeButtons.count()));

// The timer is an expiry, not decoration. Age the cart past 15 minutes and
// reload as if the guest came back to an old checkout tab.
await page.evaluate(() => {
  const items = JSON.parse(localStorage.getItem("kosipark-cart") || "[]");
  items.forEach(i => { i.pricedAt = Date.now() - 15 * 60 * 1000 - 2000; });
  localStorage.setItem("kosipark-cart", JSON.stringify(items));
});
await page.reload({ waitUntil: READY });
await page.waitForFunction(() => JSON.parse(localStorage.getItem("kosipark-cart") || "[]").length === 0, null, { timeout: 8000 }).catch(() => {});
await sleep(250);
const expiredText = await page.evaluate(() => document.body.innerText);
ok((await cart()).length === 0, "the cart is empty after 15 minutes");
ok(expiredText.includes("Your booking time has expired"), "checkout explains why the cart was cleared");

await browser.close();
if (server) server.kill();
console.log(failures === 0 ? "\nAll flow checks passed." : `\n${failures} flow check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
