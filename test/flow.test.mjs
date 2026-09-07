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

const PORT = 8811, BASE = `http://localhost:${PORT}`;
const server = spawn("python3", ["dev-server.py", String(PORT)], {
  cwd: new URL("..", import.meta.url).pathname, stdio: "ignore",
});
process.on("exit", () => server.kill());

let failures = 0;
const ok = (c, label, extra = "") => { if (!c) failures++; console.log(`${c ? "PASS" : "FAIL"}  ${label}${extra ? "  " + extra : ""}`); };

await sleep(1000);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on("pageerror", e => { failures++; console.log("FAIL  uncaught: " + e.message); });

const cart = () => page.evaluate(() => JSON.parse(localStorage.getItem("kosipark-cart") || "[]"));

const search = "arrival=2026-10-02&departure=2026-10-05&adults=2";
await page.goto(`${BASE}/book?${search}`, { waitUntil: "networkidle" });
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
await page.goto(`${BASE}/book?${search}`, { waitUntil: "networkidle" });
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

// --- picking the same thing twice must SAY so, not silently do nothing -----
// This is what the guest actually hit: choose the same type and dates again,
// land on a page showing one stay, and get no hint why.
await page.goto(`${BASE}/book?${search}`, { waitUntil: "networkidle" });
await sleep(1400);
await page.locator("article").nth(0).locator('button:has-text("Select")').first().click();
await page.waitForURL(/\/book\/checkout/, { timeout: 8000 }).catch(() => {});
await sleep(1800);
const dupText = await page.evaluate(() => document.body.innerText);
ok(/already in your booking/i.test(dupText),
   "choosing the same stay twice explains itself instead of failing quietly",
   (dupText.match(/.{0,60}already in your booking.{0,40}/i) || ["(no message)"])[0]);
const afterDup = await cart();
ok(afterDup.length === 2, "and it does not duplicate the stay", String(afterDup.length));

// --- one timer, not two ----------------------------------------------------
const timers = (text.match(/\d+:\d\d/g) || []);
ok(timers.length <= 1, "at most one countdown is shown on the checkout screen", timers.join(", "));

await browser.close();
server.kill();
console.log(failures === 0 ? "\nAll flow checks passed." : `\n${failures} flow check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
