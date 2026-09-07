/**
 * Cart behaviour, against the real guestpoint.js.
 *
 * These exist because of a live bug a guest hit and we did not: adding a
 * second stay appeared to do nothing. The cause was never the add — it was
 * that reading the cart expired the first stay a fraction of a second before
 * the second was pushed. Every assertion here is that failure, in a shape a
 * future change would have to break on purpose.
 *
 *   node test/cart.test.mjs
 */
const store = new Map();
globalThis.localStorage = {
  getItem: k => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: k => store.delete(k),
};
globalThis.window = { location: { hostname: "kosipark.com.au", href: "" } };
globalThis.location = globalThis.window.location;
globalThis.document = { addEventListener() {} };

const gp = await import("../public_html/guestpoint.js");

let failures = 0;
const ok = (cond, label, extra = "") => {
  if (!cond) failures++;
  console.log(`${cond ? "PASS" : "FAIL"}  ${label}${extra ? "  " + extra : ""}`);
};
const reset = () => store.clear();

const stay = (name, slug, over = {}) => ({
  roomTypeId: slug,
  name,
  arrival: "2026-10-02",
  departure: "2026-10-05",
  nights: 3,
  total: 690,
  adults: 2,
  children: 0,
  ...over,
});

/** Push the stored addedAt back, as if the guest had been browsing. */
const age = (minutes) => {
  const raw = JSON.parse(localStorage.getItem("kosipark-cart") || "[]");
  for (const i of raw) {
    i.addedAt -= minutes * 60000;
    if (i.pricedAt) i.pricedAt -= minutes * 60000;
  }
  localStorage.setItem("kosipark-cart", JSON.stringify(raw));
};

// --- the reported bug ------------------------------------------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
age(11); // eleven minutes of ordinary browsing
gp.addToCart(stay("Three Bedroom Chalet", "three-bedroom-chalet"));
let cart = gp.readCart();
ok(cart.length === 2, "a second stay added after 11 minutes keeps the first",
   cart.map(i => i.name).join(", "));

// --- and after a much longer detour ---------------------------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
age(6 * 60); // six hours: dinner, a phone call, a second look in the evening
gp.addToCart(stay("Powered Site", "powered-site"));
ok(gp.readCart().length === 2, "a stay added six hours later keeps the first");

// --- a selection survives a long absence on its own -----------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
age(8 * 60);
ok(gp.readCart().length === 1, "a stay is still there eight hours later");
age(20 * 60); // 28h total
ok(gp.readCart().length === 0, "a stay older than a day is gone");

// --- the timer must not count down from someone else's clock --------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
age(9);
gp.addToCart(stay("Three Bedroom Chalet", "three-bedroom-chalet"));
const left = gp.cartExpiresIn();
ok(left > 60 * 60 * 1000,
   "a freshly added stay does not inherit an almost-expired countdown",
   Math.round(left / 60000) + " min left");

// --- pricing honesty -------------------------------------------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
ok(gp.cartPricingStale() === false, "a price quoted just now is not stale");
age(45);
ok(gp.cartPricingStale() === true, "a 45-minute-old price is flagged for re-quoting");

// --- the same stay twice is still one stay --------------------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
ok(gp.readCart().length === 1, "adding the same cabin and dates twice does not duplicate it");

// --- an unpriced stay never becomes a payable line -------------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin", { total: 0 }));
ok(gp.readCart().length === 0, "a stay with no price is not put in the cart");

// --- removing works on the id we handed out --------------------------------
reset();
gp.addToCart(stay("Cedar Cabin", "cedar-cabin"));
gp.addToCart(stay("Powered Site", "powered-site"));
gp.removeFromCart(gp.readCart()[0].id);
cart = gp.readCart();
ok(cart.length === 1 && cart[0].roomTypeId === "powered-site",
   "removing one stay leaves the other");

console.log(failures === 0 ? "\nAll cart checks passed." : `\n${failures} cart check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
