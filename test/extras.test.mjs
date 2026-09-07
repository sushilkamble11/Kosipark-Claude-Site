/**
 * Extras pricing, against BookingExtraOutput as the Booking Engine spec
 * defines it — not as our mock once imagined it.
 *
 * The checkout was reading UnitPrice / PerPerson / Nights, none of which exist
 * in the spec. Every extra would have priced at NaN the moment a real API key
 * was plugged in, and the booking would have gone to GuestPoint with an Extras
 * array it could not read. These assertions are the spec, in code.
 *
 *   node test/extras.test.mjs
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
// Exercise the sample payload without a server; the shape is what is under test.
gp.CONFIG.mock = true;

let failures = 0;
const ok = (cond, label, extra = "") => {
  if (!cond) failures++;
  console.log(`${cond ? "PASS" : "FAIL"}  ${label}${extra ? "  " + extra : ""}`);
};

// A payload in the exact shape of the spec's own example.
const RAW = [
  {
    Id: "dinner", Name: "Buffet Style Dinner", Description: "Dinner each night",
    ExtraType: "checkout", CheckoutType: "service", MaxItems: 1,
    PriceType: "perPersonPerNight", DisplayOrder: 2, Images: null,
    Prices: [{ Id: 0, Name: "Adult", Price: "39" }, { Id: 1, Name: "Child", Price: "19" }],
    RatePlans: ["plan-a"],
  },
  {
    Id: "bikes", Name: "Bike Hire", Description: "Per person",
    ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 0,
    PriceType: "perPerson", DisplayOrder: 1,
    Images: [{ URL: "https://images.guestpoint.com/bike.png", Captions: { "en-AU": "" }, Sequence: "0" }],
    Prices: [{ Id: 0, Name: "Adult", Price: "17" }, { Id: 1, Name: "Child", Price: "12" }],
    RatePlans: ["plan-a"],
  },
  {
    Id: "champagne", Name: "Champagne on Arrival", Description: "Chilled on arrival",
    ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 0,
    PriceType: "perRoom", DisplayOrder: 3, Images: null,
    Prices: [{ Id: 0, Name: "", Price: "80" }],
    RatePlans: ["plan-b"],   // a different rate plan
  },
];

const ctx = { nights: 3, adults: 2, children: 1, ratePlanId: "plan-a" };
const extras = gp.normaliseExtras(RAW, ctx);

// --- shape -----------------------------------------------------------------
ok(extras.length === 2, "an extra not offered on the chosen rate plan is dropped",
   extras.map(x => x.id).join(", "));
ok(extras[0].id === "bikes", "extras come back in DisplayOrder", extras.map(x => x.id).join(", "));
ok(extras[0].image === "https://images.guestpoint.com/bike.png", "the extra's photo is carried through");

const dinner = extras.find(x => x.id === "dinner");
const bikes = extras.find(x => x.id === "bikes");

ok(dinner.isService === true && dinner.max === 1, "a service extra is taken once or not at all");
ok(bikes.max === 3, "an unlimited per-person extra is capped at the party size", String(bikes.max));
ok(bikes.prices[0].suggested === 2 && bikes.prices[1].suggested === 1,
   "adult and child counts default to who is actually staying");

// --- pricing ---------------------------------------------------------------
// Dinner: per person PER NIGHT. 2 adults x $39 + 1 child x $19, over 3 nights.
ok(gp.extraCost(dinner, { 0: 2, 1: 1 }, 3) === (2 * 39 + 19) * 3,
   "perPersonPerNight multiplies by guests and by nights",
   String(gp.extraCost(dinner, { 0: 2, 1: 1 }, 3)));

// Bikes: per person, ONCE. Nights must not enter into it.
ok(gp.extraCost(bikes, { 0: 2, 1: 1 }, 3) === 2 * 17 + 12,
   "perPerson does not multiply by nights", String(gp.extraCost(bikes, { 0: 2, 1: 1 }, 3)));

ok(gp.extraCost(bikes, {}, 3) === 0, "an extra nobody chose costs nothing");
ok(gp.extraCost(bikes, { 0: -5 }, 3) === 0, "a negative count cannot credit the booking");

// perNight and perBooking, from the site's own mock.
const mockCtx = { nights: 4, adults: 2, children: 0 };
const mock = gp.normaliseExtras(await gp.getExtras([{ Arrival: "2026-10-02", Departure: "2026-10-06" }]), mockCtx);
const vehicle = mock.find(x => x.id === "extra-vehicle");
const firewood = mock.find(x => x.id === "firewood");
ok(gp.extraCost(vehicle, { 0: 1 }, 4) === 20 * 4, "perNight multiplies by nights", String(gp.extraCost(vehicle, { 0: 1 }, 4)));
ok(gp.extraCost(firewood, { 0: 3 }, 4) === 60, "perBooking is charged once whatever the stay", String(gp.extraCost(firewood, { 0: 3 }, 4)));

// Nothing in the mock prices as NaN — the failure this file exists to prevent.
const anyNaN = mock.some(x => x.prices.some(pr => !Number.isFinite(pr.price)));
ok(!anyNaN, "no extra prices as NaN");

// --- totals ----------------------------------------------------------------
const chosen = { dinner: { 0: 2, 1: 1 }, bikes: { 0: 2 } };
ok(gp.extrasTotal(extras, chosen, 3) === (2 * 39 + 19) * 3 + 2 * 17,
   "the total adds every chosen extra", String(gp.extrasTotal(extras, chosen, 3)));

// --- what goes back to GuestPoint ------------------------------------------
const forBooking = gp.extrasForBooking(extras, chosen);
ok(forBooking.length === 2, "only chosen extras are sent");
const sentDinner = forBooking.find(e => e.Id === "dinner");
ok(JSON.stringify(sentDinner.Counts) === JSON.stringify([{ PriceId: 0, Count: 2 }, { PriceId: 1, Count: 1 }]),
   "one Counts entry per price, as the spec requires", JSON.stringify(sentDinner.Counts));
const sentBikes = forBooking.find(e => e.Id === "bikes");
ok(sentBikes.Counts.length === 1 && sentBikes.Counts[0].PriceId === 0,
   "a price nobody took is left out rather than sent as zero", JSON.stringify(sentBikes.Counts));

console.log(failures === 0 ? "\nAll extras checks passed." : `\n${failures} extras check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
