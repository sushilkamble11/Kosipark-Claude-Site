/**
 * GuestPoint Booking Engine client for the Kosipark site.
 *
 * Nothing here ever holds the API key. Every call goes to CONFIG.baseUrl, which
 * must be your own proxy (Cloudflare Worker / middleware). The proxy adds the
 * X-API-KEY header, forwards to https://beapi.guestpoint.dev/api/v1, and caches
 * the response at the edge. See CACHE_TTL below for the browser-side half.
 *
 * Until credentials exist, CONFIG.mock = true serves realistic fixtures so the
 * whole site is clickable. Flip mock to false and set baseUrl/propertyId to go live.
 */

/**
 * Deployment config. There is deliberately nothing to edit here to go live.
 *
 *  - baseUrl is the PHP proxy at /api/gp. The proxy holds the API key; this
 *    file never does.
 *  - propertyId is the literal "self". The proxy substitutes the real property
 *    id from its own config, so the id is not in the repo and a visitor cannot
 *    point our key at another property by editing the URL.
 *  - mock follows the host: on (and never calling out) on localhost and
 *    file://, off everywhere else. Override with window.KOSIPARK_MOCK = true
 *    before the module loads, or in the console via __gp.setMock(true).
 */
function autoMock() {
  if (typeof window === "undefined") return true;
  if (typeof window.KOSIPARK_MOCK === "boolean") return window.KOSIPARK_MOCK;
  const h = location.hostname;
  return location.protocol === "file:" || h === "localhost" || h === "127.0.0.1" ||
         h === "[::1]" || h.endsWith(".local") || h.endsWith(".test");
}

export const CONFIG = {
  baseUrl: (typeof window !== "undefined" && window.KOSIPARK_API_BASE) || "/api/gp",
  propertyId: "self",
  mock: autoMock(),
  currency: "AUD"
};

/**
 * slug -> GuestPoint room type.
 *
 * `id` is optional. Left empty, the type is matched against live data by name
 * (case- and punctuation-insensitive) and the discovered id is remembered for
 * the session. Fill `id` in only if two types ever share a name.
 */
export const ROOM_TYPES = {
  "three-bedroom-chalet": { id: "", name: "Three Bedroom Chalet", maxOccupancy: 8, mockRate: 289 },
  "two-bedroom-chalet-upgraded": { id: "", name: "Two Bedroom Chalet Upgraded", maxOccupancy: 6, mockRate: 245 },
  "two-bedroom-chalet": { id: "", name: "Two Bedroom Chalet", maxOccupancy: 6, mockRate: 219 },
  "cedar-cabin": { id: "", name: "Cedar Cabin", maxOccupancy: 6, mockRate: 175 },
  "caravan-site": { id: "", name: "Caravan Site", maxOccupancy: 6, mockRate: 62 },
  "camper-trailer-site": { id: "", name: "Camper Trailer Site", maxOccupancy: 6, mockRate: 62 },
  "motorhome-site": { id: "", name: "Motorhome Site", maxOccupancy: 6, mockRate: 55 },
  "unpowered-site": { id: "", name: "Unpowered Site", maxOccupancy: 6, mockRate: 42 }
};

/** Browser cache lifetimes, milliseconds. The proxy should cache the same calls at the edge. */
const CACHE_TTL = {
  availabilities: 5 * 60 * 1000,
  bestavailablerates: 15 * 60 * 1000,
  promocodes: 10 * 60 * 1000,
  extras: 15 * 60 * 1000,
  beprofilefields: 24 * 60 * 60 * 1000
};

const memory = new Map();
const LS_PREFIX = "gp:";

function cacheGet(key, ttl) {
  const hit = memory.get(key);
  if (hit && Date.now() - hit.ts < ttl) return hit.value;
  try {
    const raw = localStorage.getItem(LS_PREFIX + key);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (Date.now() - parsed.ts < ttl) {
        memory.set(key, parsed);
        return parsed.value;
      }
      localStorage.removeItem(LS_PREFIX + key);
    }
  } catch (e) {}
  return null;
}

function cacheSet(key, value) {
  const entry = { value, ts: Date.now() };
  memory.set(key, entry);
  try { localStorage.setItem(LS_PREFIX + key, JSON.stringify(entry)); } catch (e) {}
}

export function clearCache() {
  memory.clear();
  try {
    Object.keys(localStorage).filter(k => k.indexOf(LS_PREFIX) === 0).forEach(k => localStorage.removeItem(k));
  } catch (e) {}
}

function qs(params) {
  const p = new URLSearchParams();
  Object.keys(params).forEach(k => {
    if (params[k] !== undefined && params[k] !== null && params[k] !== "") p.set(k, params[k]);
  });
  const s = p.toString();
  return s ? "?" + s : "";
}

/**
 * Maps a slug, or a comma-separated list of them, to GuestPoint external ids.
 * Anything already an id, or not yet discovered, passes through untouched.
 */
function toRoomTypeIds(value) {
  if (!value) return value;
  return String(value).split(",").map(v => {
    const t = v.trim();
    return roomTypeIdFor(t) || t;
  }).join(",");
}

/** Every request funnels through here: cache lookup, fetch, cache store, error shaping. */
async function request(method, path, { params, body, cacheKind } = {}) {
  const url = CONFIG.baseUrl + "/properties/" + CONFIG.propertyId + path + qs(params || {});
  const ttl = cacheKind ? CACHE_TTL[cacheKind] : 0;
  const key = cacheKind ? method + " " + url : null;

  if (key && ttl) {
    const cached = cacheGet(key, ttl);
    if (cached) return cached;
  }

  if (CONFIG.mock) {
    announceSample();
    const mocked = await mockResponse(method, path, params, body);
    if (key && ttl) cacheSet(key, mocked);
    return mocked;
  }

  if (notConfigured) {                     // already learned there are no credentials
    const mocked = await mockResponse(method, path, params, body);
    if (key && ttl) cacheSet(key, mocked);
    return mocked;
  }

  const res = await fetch(url, {
    method,
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: body ? JSON.stringify(body) : undefined
  });

  let payload = null;
  try { payload = await res.json(); } catch (e) {}

  // Two shapes mean "no credentials yet": the proxy's 200 marker (what it sends
  // now — quiet, because the browser does not log a 200) and a 503 (older
  // proxy builds, and any deploy where the two are briefly out of step).
  //
  // Every one of them falls back, not just the first. The pages fire several
  // calls at once on load, so they are all in flight before any has learned the
  // service is unconfigured — guarding this on the flag left the rest throwing.
  if (res.status === 503 || (payload && payload.Unconfigured === true)) {
    if (!notConfigured) {
      notConfigured = true;
      console.info("[guestpoint] booking service not configured yet — showing sample data");
    }
    announceSample();
    const mocked = await mockResponse(method, path, params, body);
    if (key && ttl) cacheSet(key, mocked);
    return mocked;
  }

  if (!res.ok) {
    const err = new Error((payload && (payload.message || payload.error)) || "GuestPoint request failed (" + res.status + ")");
    err.status = res.status;
    err.payload = payload;
    throw err;
  }

  const data = payload && payload.data !== undefined ? payload.data : payload;
  if (key && ttl) cacheSet(key, data);
  return data;
}

/**
 * Before credentials exist the proxy answers 503 "not configured yet". Without
 * this the site would sit there throwing console errors with every rate, every
 * calendar and every photo blank — unreviewable. So the first 503 flips the
 * client into mock mode for the rest of the page, and the site fills with
 * clearly-sample data instead. The banner in site.js says so out loud, so a
 * sample rate is never mistaken for a real one.
 *
 * This only ever triggers on 503 from our own proxy. A real GuestPoint error —
 * a bad key, a network fault, a rejected reservation — still surfaces as an
 * error, because quietly serving invented prices over a live booking engine is
 * the one thing this must never do.
 */
let notConfigured = false;
let announced = false;
export function isUnconfigured() { return notConfigured || CONFIG.mock; }

function announceSample() {
  if (announced) return;
  announced = true;
  try { window.dispatchEvent(new CustomEvent("kosipark:sample-data")); } catch (e) {}
}

/* ---------- Endpoints ---------- */

/** 1. GET /availabilities — room types, availability and rates for a stay. */
export function searchAvailability({ arrivalDate, departureDate, numAdults = 2, numChildren = 0, calendarMode, promoCode, roomTypes, ratePlans }) {
  return request("GET", "/availabilities", {
    params: { arrivalDate, departureDate, numAdults, numChildren, calendarMode, promoCode,
              roomTypes: toRoomTypeIds(roomTypes), ratePlans },
    cacheKind: "availabilities"
  });
}

/** 2. GET /bestavailablerates — best rate per day, for calendars and "from $X" figures. */
export function bestAvailableRates({ fromDate, toDate, roomType, numAdults, numChildren, promoCode }) {
  return request("GET", "/bestavailablerates", {
    // Callers pass slugs; GuestPoint wants external ids. When the id is not
    // known yet the slug goes through unchanged, which is what mock mode wants.
    params: { fromDate, toDate, roomType: toRoomTypeIds(roomType),
              numAdults, numChildren, promoCode },
    cacheKind: "bestavailablerates"
  });
}

/** 3. GET /promocodes/{code} */
export function checkPromoCode(code) {
  return request("GET", "/promocodes/" + encodeURIComponent(code), { cacheKind: "promocodes" });
}

/** 4. POST /extras — add-ons eligible for the selected room stays. */
export function getExtras(roomStays) {
  return request("POST", "/extras", { body: { RoomStays: roomStays }, cacheKind: "extras" });
}

/** 5. GET /beprofilefields — the extra guest fields this property collects. */
export function getProfileFields({ includeInactive } = {}) {
  return request("GET", "/beprofilefields", { params: { includeInactive }, cacheKind: "beprofilefields" });
}

/**
 * The rest of the site identifies a room type by its slug — it is in the URLs,
 * the cart and the copy. GuestPoint wants its own external id. This is the one
 * place the swap happens, on the way out.
 */
function withRealRoomTypeIds(reservation) {
  const stays = reservation && reservation.RoomStays;
  if (!Array.isArray(stays)) return reservation;
  return { ...reservation, RoomStays: stays.map(st => {
    const real = roomTypeIdFor(st.RoomTypeId);
    return real && real !== st.RoomTypeId ? { ...st, RoomTypeId: real } : st;
  }) };
}

/** 6. PATCH /reservations — validate, recalculate, and get VerificationCode + deposit. Never cached. */
export function validateReservation(reservation) {
  return request("PATCH", "/reservations", { body: { PropertyId: CONFIG.propertyId, ...withRealRoomTypeIds(reservation) } });
}

/** 7. POST /reservations — create. Requires the VerificationCode from validate. Never cached. */
export function createReservation(reservation) {
  return request("POST", "/reservations", { body: { PropertyId: CONFIG.propertyId, Status: "Booked", ...withRealRoomTypeIds(reservation) } });
}

/* ---------- Self-service management ----------
 * The Booking Engine API does have guest self-service, on its own endpoints.
 * Three calls, all authenticated by what the guest knows rather than a login:
 * confirmation number + the lead guest's email + surname, all three matching.
 *
 * The response carries a `Login` object saying which actions this property
 * actually permits (Cancel, PayNow, UpdateCreditCard, ...). Read it and hide
 * what is not allowed — do not assume.
 */

/** 8. POST /reservations/manage — look a booking up. The three fields must all match. */
export function lookupReservation({ confNum, email, surname }) {
  return request("POST", "/reservations/manage", {
    body: { ConfNum: confNum, EmailAddress: email, Surname: surname }
  });
}

/**
 * 9. PATCH /reservations/manage/{confNum} — partial update.
 *
 * Two traps worth remembering: `Guests` replaces the entire guest list, so send
 * every guest and not just the changed one; and a reservation cannot be
 * modified once any room stay's arrival date is in the past.
 */
export function modifyReservation(confNum, changes) {
  return request("PATCH", "/reservations/manage/" + encodeURIComponent(confNum), {
    body: changes
  });
}

/**
 * 10. DELETE /reservations/{reservationId} — cancel. Takes the internal integer
 * id from the lookup, and the ConfNum must match it. Only works while the
 * reservation is Booked or Modified.
 */
export function cancelReservation(reservationId, confNum) {
  return request("DELETE", "/reservations/" + encodeURIComponent(reservationId), {
    body: { PropertyId: CONFIG.propertyId, ConfNum: confNum }
  });
}


/* ---------- Booking cart ----------
   A reservation can hold several RoomStays, so one payment can cover a cabin
   plus a site. The cart lives in the browser until checkout builds RoomStays[]. */

const CART_KEY = "kosipark-cart";
/** A held stay is only a browser convenience — it lapses after ten minutes. */
export const CART_TTL = 10 * 60 * 1000;

export function cartExpiresIn() {
  const items = readCart();
  if (!items.length) return 0;
  const oldest = Math.min.apply(null, items.map(i => i.addedAt || 0));
  return Math.max(0, CART_TTL - (Date.now() - oldest));
}

export function readCart() {
  try {
    const raw = localStorage.getItem(CART_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    const now = Date.now();
    const live = parsed.filter(i => Number(i.total) > 0 && now - (i.addedAt || 0) < CART_TTL);
    if (live.length !== parsed.length) {
      try { localStorage.setItem(CART_KEY, JSON.stringify(live)); } catch (e) {}
    }
    return live;
  } catch (e) { return []; }
}

export function writeCart(items) {
  try { localStorage.setItem(CART_KEY, JSON.stringify(items)); } catch (e) {}
  return items;
}

export function addToCart(item) {
  const items = readCart();
  const id = item.id || (item.roomTypeId + ":" + item.arrival + ":" + item.departure + ":" + Date.now());
  const exists = items.some(i => i.roomTypeId === item.roomTypeId && i.arrival === item.arrival && i.departure === item.departure);
  if (exists) return items;
  items.push({ ...item, id, addedAt: Date.now() });
  return writeCart(items);
}

export function removeFromCart(id) {
  return writeCart(readCart().filter(i => i.id !== id));
}

export function clearCart() { return writeCart([]); }

export function cartTotal() {
  return readCart().reduce((sum, i) => sum + (Number(i.total) || 0), 0);
}

/* ---------- Helpers the pages use ---------- */

export function nightsBetween(a, b) {
  return Math.max(0, Math.round((new Date(b) - new Date(a)) / 86400000));
}

export function money(n, currency = CONFIG.currency) {
  const v = typeof n === "string" ? parseFloat(n) : n;
  if (isNaN(v)) return "";
  return (currency === "AUD" ? "$" : currency + " ") + v.toFixed(2).replace(/\.00$/, "");
}

/**
 * Flattens an /availabilities response into one row per room type.
 *
 * Reads the documented shape first (RoomTypeAvailability: MaxGuests,
 * BedroomCount, RoomImages, Availabilities[].ForSale, RatePlans[].Rates[]),
 * and falls back to the looser field names the mock fixtures use, so the same
 * mapping serves both. Every numeric field the API returns as a string is
 * parsed here and nowhere else.
 */
export function flattenAvailability(data) {
  const prop = data && data.Properties && data.Properties[0];
  if (!prop) return [];
  learnRoomTypes(prop.RoomTypes);

  return (prop.RoomTypes || []).map(rt => {
    // --- nightly availability -------------------------------------------
    // Availabilities carries one entry per night of the requested stay.
    const nights = Array.isArray(rt.Availabilities) ? rt.Availabilities : [];
    const counts = nights
      .map(n => (typeof n.ForSale === "number" ? n.ForSale : null))
      .filter(n => n !== null);
    // The stay can only be sold as many times as its tightest night allows.
    const roomsLeft = counts.length ? Math.min(...counts)
                    : (typeof rt.RoomsLeft === "number" ? rt.RoomsLeft : null);
    const anyNightClosed = nights.some(n => n.Closed === true);

    // --- rate plans ------------------------------------------------------
    const plans = (rt.RatePlans || []).map(p => {
      const rates = Array.isArray(p.Rates) ? p.Rates.slice().sort(byDate) : [];
      // Spec: no plan-level Total. The stay total is the sum of its nights.
      const total = rates.length
        ? rates.reduce((s, r) => s + num(r.SellRate), 0)
        : num(p.Total !== undefined ? p.Total : p.RoomTotal);
      const strike = rates.length
        ? rates.reduce((s, r) => s + num(r.StrikeOutRate !== undefined ? r.StrikeOutRate : r.SellRate), 0)
        : 0;
      const first = rates[0] || {};
      const last  = rates[rates.length - 1] || {};
      return {
        ratePlanId: p.Id || p.RatePlanId,
        name: p.Name,
        description: p.Description || "",
        total,
        nightly: rates.map(r => ({ date: r.Date, rate: num(r.SellRate) })),
        policyText: p.PolicyText || "",
        inclusions: p.Inclusions || "",
        cancellation: (p.Policy && p.Policy.CancelRule && p.Policy.CancelRule.CancelRuleText)
                      || p.CancellationText || "",
        deposit: p.Policy && p.Policy.DepositRule ? p.Policy.DepositRule : null,
        saving: strike > total ? strike - total : (p.Saving ? num(p.Saving) : 0),
        minNights: p.MinStay || p.MinNights || 0,
        sortOrder: typeof p.WebSortOrder === "number" ? p.WebSortOrder : 999,
        // Restrictions are per-date on the rate, not per room type.
        closed: rates.length ? rates.some(r => r.Closed === true) : false,
        closedToArrival: first.ClosedToArrival === true,
        closedToDeparture: last.ClosedToDeparture === true,
        closedDueToCriteria: rates.some(r => r.ClosedDueToCriteria === true),
        minStayArrival: num(first.MinStayArrival) || 0
      };
    }).filter(p => p.total > 0 || p.closed)
      .sort((a, b) => a.total - b.total);

    const sellable = plans.filter(p => !p.closed && !p.closedToArrival &&
                                       !p.closedToDeparture && p.total > 0);
    const cheapest = sellable[0] || null;

    // --- why can't this be booked? --------------------------------------
    // One reason, chosen in the order a guest would care about, so the card
    // can always show a plain-English line and a fix.
    let restriction = null;
    if (!cheapest && plans.length) {
      const blocked = plans[0];
      if (blocked.minStayArrival > 1) {
        restriction = { code: "MIN_STAY", minNights: blocked.minStayArrival,
          message: blocked.minStayArrival + " night minimum on these dates" };
      } else if (blocked.closedToArrival) {
        restriction = { code: "CLOSED_TO_ARRIVAL", message: "No arrivals on this date" };
      } else if (blocked.closedToDeparture) {
        restriction = { code: "CLOSED_TO_DEPARTURE", message: "No departures on this date" };
      } else if (blocked.closed || anyNightClosed) {
        restriction = { code: "SOLD_OUT", message: "Not available on these dates" };
      }
    } else if (rt.Restriction) {
      restriction = {
        code: rt.Restriction.Code,
        message: rt.Restriction.Message,
        minNights: rt.Restriction.MinNights || 0,
        maxNights: rt.Restriction.MaxNights || 0
      };
    }

    const maxGuests = rt.MaxGuests !== undefined ? rt.MaxGuests : rt.MaxOccupancy;
    const slug = slugForRoomType(rt) || String(rt.Id || rt.RoomTypeId || "");

    return {
      // The whole front end — URLs, cart, copy lookups, category tests — keys
      // off the slug, so that is what `roomTypeId` carries. GuestPoint's own
      // id is kept alongside it and swapped back in at the API boundary.
      roomTypeId: slug,
      slug,
      gpRoomTypeId: rt.Id || rt.RoomTypeId,
      name: rt.Name,
      description: rt.Description,
      maxOccupancy: maxGuests,
      sleeps: maxGuests,
      maxAdults: rt.MaxAdults || null,
      maxChildren: rt.MaxChildren || null,
      bedrooms: rt.BedroomCount !== undefined ? rt.BedroomCount : (rt.Bedrooms || 0),
      bathrooms: rt.BathroomCount || 0,
      bedding: rt.Bedding || null,
      facilities: rt.Facilities || [],
      cars: rt.Cars || 1,
      isSite: rt.IsSite !== undefined ? !!rt.IsSite
              : /site|camp/i.test(String(rt.Classification || slug || "")),
      roomsLeft,
      // UrgencyMessages is GuestPoint's own scarcity copy — real, not invented.
      urgency: (rt.UrgencyMessages || []).map(u => u.Message).filter(Boolean),
      minNights: (cheapest && cheapest.minNights) || rt.MinNights || 0,
      maxNights: rt.MaxNights || 0,   // not in the BE spec; present only in mock
      restriction,
      available: !!cheapest && !anyNightClosed && rt.Available !== false,
      plans,
      ratePlanId: cheapest && cheapest.ratePlanId,
      ratePlanName: cheapest && cheapest.name,
      total: cheapest ? cheapest.total : null,
      policyText: cheapest && cheapest.policyText,
      images: normaliseImages(rt.RoomImages || rt.Images || rt.RoomTypeImages)
    };
  });
}

function num(v) {
  const n = typeof v === "string" ? parseFloat(v) : v;
  return isFinite(n) ? n : 0;
}
function byDate(a, b) { return String(a.Date || "").localeCompare(String(b.Date || "")); }

/**
 * Enquiry capture. Posts the guest's details to YOUR middleware (not GuestPoint —
 * it has no lead endpoint) as soon as the details step is completed, so an
 * abandoned booking can be followed up. Set LEAD_ENDPOINT to your own URL;
 * while it is empty the payload is only logged, never sent.
 */
export const LEAD_ENDPOINT = "";

export async function captureLead(lead) {
  const payload = { ...lead, capturedAt: new Date().toISOString(), source: "kosipark-web" };
  if (!LEAD_ENDPOINT) { console.info("[lead capture — no endpoint configured]", payload); return { stored: false }; }
  try {
    await fetch(LEAD_ENDPOINT, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    return { stored: true };
  } catch (e) {
    return { stored: false, error: e.message };
  }
}

/** Price of adding the night before / after a stay, for the "extend your stay" upsell. */
export async function extendQuotes({ roomType, arrival, departure, numAdults, numChildren }) {
  const before = new Date(arrival); before.setDate(before.getDate() - 1);
  const after = new Date(departure); after.setDate(after.getDate() + 1);
  const iso = d => d.toISOString().slice(0, 10);
  const data = await bestAvailableRates({ fromDate: iso(before), toDate: iso(after), roomType, numAdults, numChildren });
  const days = normaliseDays(data, roomType);
  const beforeDay = days[iso(before)];
  const departureDay = days[departure];
  return {
    before: beforeDay && !beforeDay.closed ? { date: iso(before), rate: beforeDay.rate } : null,
    after: departureDay && !departureDay.closed ? { date: departure, rate: departureDay.rate } : null
  };
}

/**
 * Turns a bestavailablerates Days map into { "YYYY-MM-DD": {rate, closed, ...} }.
 *
 * `room` may be a slug or a GuestPoint id — both resolve. When the request was
 * scoped to one room type the response carries a single calendar, so an
 * unmatched id falls back to the first rather than returning nothing.
 *
 * Note on MaxStay: it is not in the Booking Engine spec. The field is carried
 * through when present (the mock emits it) but must not be relied on live.
 */
export function normaliseDays(data, room) {
  const props = (data && data.Properties) || [];
  learnRoomTypes(props);
  const wantId = room ? (roomTypeIdFor(room) || room) : null;
  const prop = wantId
    ? props.find(p => p.RoomTypeId === wantId || p.RoomTypeId === room) || props[0]
    : props[0];
  const out = {};
  if (!prop || !prop.Days) return out;

  Object.keys(prop.Days).forEach(date => {
    const d = prop.Days[date] || {};
    // Spec: BARDay.RoomAvailable (boolean) + BestRate (string).
    const soldOut = d.RoomAvailable === false || d.Closed === true ||
                    d.Available === false || d.Availability === 0;
    const rate = d.BestRate !== undefined ? num(d.BestRate)
               : d.Rate !== undefined ? num(d.Rate) : null;
    out[date] = {
      rate: rate || null,
      strikeRate: d.StrikeOutRate !== undefined ? num(d.StrikeOutRate) : null,
      discountReason: d.DiscountReason || "",
      soldOut,
      closed: soldOut,
      // BARDay has no per-day count; scarcity comes from /availabilities instead.
      count: typeof d.Available === "number" ? d.Available
           : (typeof d.Availability === "number" ? d.Availability : null),
      minStay: d.MinStay || d.MinimumStay || null,
      maxStay: d.MaxStay || d.MaximumStay || null,
      closedToArrival: d.ClosedToArrival === true || d.CTA === true,
      closedToDeparture: d.ClosedToDeparture === true || d.CTD === true,
      ratePlan: d.RatePlan || null
    };
  });
  return out;
}

/* ---------- Room type registry ----------
 * The site's URLs, calendars and cart all speak slugs ("cedar-cabin").
 * GuestPoint speaks external ids. This is the only place the two meet: ids are
 * learned from whatever response happens to arrive first and remembered for
 * the session, so nobody has to paste eight GUIDs into a config file.
 */

const SLUG_BY_ID = new Map();
const ID_BY_SLUG = new Map();
const IMAGES_BY_SLUG = new Map();
let PROPERTY_META = null;

const norm = s => String(s || "").toLowerCase().replace(/[^a-z0-9]+/g, "");

/** Seed from anything the config already declares. */
Object.keys(ROOM_TYPES).forEach(slug => {
  const id = ROOM_TYPES[slug].id;
  if (id) { ID_BY_SLUG.set(slug, id); SLUG_BY_ID.set(id, slug); }
});

function slugForRoomType(rt) {
  const id = rt.Id || rt.RoomTypeId;
  if (id && SLUG_BY_ID.has(id)) return SLUG_BY_ID.get(id);
  const name = rt.Name || rt.RoomTypeName;
  if (!name) return null;                 // BAR calendars often carry neither
  const wanted = norm(name);
  const hit = Object.keys(ROOM_TYPES).find(s => norm(ROOM_TYPES[s].name) === wanted);
  if (hit) return hit;
  // Unknown type — derive a stable slug rather than dropping the row.
  return String(name).toLowerCase().trim()
           .replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || String(id || "room");
}

/** Records slug<->id and per-type images from any availability-ish payload. */
function learnRoomTypes(list) {
  (list || []).forEach(rt => {
    if (!rt) return;
    const id = rt.Id || rt.RoomTypeId;
    const slug = slugForRoomType(rt) || String(rt.Id || rt.RoomTypeId || "");
    if (!slug) return;
    if (id) { SLUG_BY_ID.set(id, slug); ID_BY_SLUG.set(slug, id); }
    const imgs = normaliseImages(rt.RoomImages || rt.Images || rt.RoomTypeImages);
    if (imgs.length) IMAGES_BY_SLUG.set(slug, imgs);
  });
}

/** slug (or id) -> GuestPoint external id, or null if not discovered yet. */
export function roomTypeIdFor(slugOrId) {
  if (!slugOrId) return null;
  if (ID_BY_SLUG.has(slugOrId)) return ID_BY_SLUG.get(slugOrId);
  if (SLUG_BY_ID.has(slugOrId)) return slugOrId;   // already an id
  return null;
}

/** GuestPoint external id -> slug. */
export function slugForRoomTypeId(id) { return SLUG_BY_ID.get(id) || null; }

/** What the registry currently knows. Handy in the console when going live. */
export function roomTypeMap() {
  return Object.fromEntries(Array.from(ID_BY_SLUG.entries()));
}

/* ---------- Images from GuestPoint ----------
 * Spec: PropertyAvailability.PropertyImages[] and RoomTypeAvailability.RoomImages[],
 * each an Image { URL, Captions: {lang: text}, Sequence }. Sequence is a string,
 * so it is compared numerically here.
 */

function normaliseImages(list) {
  if (!Array.isArray(list)) return [];
  return list
    .map(img => ({
      url: img.URL || img.Url || img.url || "",
      caption: pickCaption(img.Captions),
      sequence: parseInt(img.Sequence, 10)
    }))
    .filter(i => i.url)
    .sort((a, b) => (isFinite(a.sequence) ? a.sequence : 1e9) -
                    (isFinite(b.sequence) ? b.sequence : 1e9));
}

function pickCaption(captions) {
  if (!captions || typeof captions !== "object") return "";
  const lang = (typeof navigator !== "undefined" && navigator.language || "en").slice(0, 2);
  return captions[lang] || captions["en"] || captions["en-AU"] ||
         Object.values(captions)[0] || "";
}

/**
 * One call that fetches everything content-shaped: property description,
 * address, rating, coordinates, facilities, and every photo GuestPoint holds.
 * Cached for a day — this is brochure data, not availability.
 *
 * It piggybacks on /availabilities (the only endpoint that returns content)
 * using a short throwaway window a fortnight out, so it never collides with a
 * real search in the cache and never reports availability to anyone.
 */
export async function getPropertyContent() {
  if (PROPERTY_META) return PROPERTY_META;
  const from = new Date(); from.setDate(from.getDate() + 14);
  const to = new Date(from); to.setDate(to.getDate() + 1);
  const iso = d => d.toISOString().slice(0, 10);

  let data = null;
  try {
    data = await request("GET", "/availabilities", {
      params: { arrivalDate: iso(from), departureDate: iso(to), numAdults: 2, calendarMode: true },
      cacheKind: "beprofilefields"      // reuse the 24-hour bucket
    });
  } catch (e) {
    console.info("[guestpoint] no property content yet — placeholders will be drawn");
    return (PROPERTY_META = { images: [], rooms: {}, failed: true });
  }

  const prop = (data && data.Properties && data.Properties[0]) || {};
  learnRoomTypes(prop.RoomTypes);

  PROPERTY_META = {
    id: prop.Id || null,
    name: prop.Name || "",
    description: prop.Description || "",
    address: prop.StreetAddress || null,
    contact: prop.Contact || null,
    facilities: prop.Facilities || {},
    rating: prop.Rating ? { value: prop.Rating, type: prop.RatingType || "" } : null,
    coords: prop.Latitude && prop.Longitude
            ? { lat: parseFloat(prop.Latitude), lng: parseFloat(prop.Longitude),
                zoom: parseInt(prop.ZoomLevel, 10) || 14 }
            : null,
    images: normaliseImages(prop.PropertyImages),
    rooms: Object.fromEntries(Array.from(IMAGES_BY_SLUG.entries())),
    failed: false
  };
  return PROPERTY_META;
}

/** Photos for one room type, or the property's own when a slug is not given. */
export async function imagesFor(slug) {
  if (slug && IMAGES_BY_SLUG.has(slug)) return IMAGES_BY_SLUG.get(slug);
  const meta = await getPropertyContent();
  if (!slug) return meta.images || [];
  return (meta.rooms && meta.rooms[slug]) || IMAGES_BY_SLUG.get(slug) || [];
}

/* ---------- Mock data (used only while CONFIG.mock is true) ---------- */

/**
 * Mock photos. GuestPoint returns real CDN URLs; these are inline SVGs so the
 * local preview shows the photo pipeline working — slots filled, captions
 * carried through — without fetching anything or shipping stock imagery.
 */
function mockImage(label, tone, seq) {
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800">' +
    '<rect width="1200" height="800" fill="' + tone + '"/>' +
    '<text x="600" y="392" text-anchor="middle" font-family="Georgia, serif" font-size="46" fill="#FCFAF6">' +
      label.replace(/&/g, "&amp;").replace(/</g, "&lt;") + '</text>' +
    '<text x="600" y="446" text-anchor="middle" font-family="Helvetica, sans-serif" font-size="22" fill="rgba(252,250,246,0.62)">' +
      'GuestPoint photo ' + seq + '</text></svg>';
  return {
    URL: "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg),
    Sequence: String(seq),
    Captions: { en: label + " — photo " + seq }
  };
}

const MOCK_TONES = ["#1D3730", "#3A4E3C", "#74836A", "#5B4630", "#96592A", "#122720"];

function mockRoomImages(name, i) {
  return [1, 2, 3].map(n => mockImage(name, MOCK_TONES[(i + n) % MOCK_TONES.length], n));
}

function mockPropertyImages() {
  const scenes = ["Sawpit Creek", "Snow gums", "Camp kitchen", "Park entrance",
                  "Chalet row", "Creek walk", "Campfire", "Winter morning",
                  "Bush setting", "Amenities block", "Kangaroos at dusk", "Perisher road",
                  "Summer meadow", "Chalet deck", "Night sky", "Reception"];
  return scenes.map((label, i) => mockImage(label, MOCK_TONES[i % MOCK_TONES.length], i + 1));
}

function snowSeed(n) { return [1, 2, 4, 6, 3, 1, 5, 2, 8][n] || 3; }

function mockRateFor(slug, dateStr) {
  const base = ROOM_TYPES[slug] ? ROOM_TYPES[slug].mockRate : 120;
  const d = new Date(dateStr);
  const month = d.getMonth() + 1;
  const day = d.getDay();
  const snow = month >= 6 && month <= 9;
  const weekend = day === 5 || day === 6;
  let rate = base * (snow ? 1.35 : 1) * (weekend ? 1.15 : 1);
  return Math.round(rate);
}

async function mockResponse(method, path, params, body) {
  await new Promise(r => setTimeout(r, 220));

  if (path === "/availabilities") {
    const nights = nightsBetween(params.arrivalDate, params.departureDate) || 1;
    const guests = Number(params.numAdults || 2) + Number(params.numChildren || 0);
    return {
      Properties: [{
        Id: "mock-property",
        Name: "Kosciuszko Tourist Park",
        CurrencyCode: "AUD",
        Latitude: "-36.4064", Longitude: "148.5296", ZoomLevel: "13",
        Rating: "4.4", RatingType: "Guest",
        PropertyImages: mockPropertyImages(),
        RoomTypes: Object.keys(ROOM_TYPES).map((slug, i) => {
          const rt = ROOM_TYPES[slug];
          const perNight = mockRateFor(slug, params.arrivalDate);
          const isSite = slug.indexOf("site") > -1;
          const closedOut = slug === "unpowered-site" && new Date(params.arrivalDate).getMonth() + 1 >= 6 && new Date(params.arrivalDate).getMonth() + 1 <= 9;
          const arrMonth = new Date(params.arrivalDate).getMonth() + 1;
          const arrDow = new Date(params.arrivalDate).getDay();
          const typeMin = (arrMonth >= 6 && arrMonth <= 9 && (arrDow === 5 || arrDow === 6)) ? 2 : 1;
          const minFail = nights < typeMin;
          const ctaFail = arrMonth >= 6 && arrMonth <= 9 && arrDow === 6 && !isSite;
          const maxFail = nights > 21;
          const ok = !closedOut && guests <= rt.maxOccupancy && !minFail && !ctaFail && !maxFail;
          const stay = perNight * nights;
          const plans = [{
            Id: "standard", Name: "Standard rate", Description: "Our most flexible rate",
            Total: String(stay),
            CancellationText: "Free changes 15+ days out",
            PolicyText: "Deposit of 50% or one full night's tariff, whichever is higher. Cancellation fees apply within 14 days."
          }];
          const arrivalDow = new Date(params.arrivalDate).getDay();
          if (nights >= 2 && arrivalDow >= 1 && arrivalDow <= 3) {
            plans.push({
              Id: "midweek", Name: "Midweek saver", Description: "Arrive Mon–Wed and save 12%",
              Total: String(Math.round(stay * 0.88)), Saving: String(Math.round(stay * 0.12)),
              CancellationText: "Free changes 15+ days out",
              PolicyText: "Deposit of 50% or one night's tariff. Same cancellation terms as the standard rate."
            });
          }
          plans.push({
            Id: "advance", Name: "Book early, pay less", Description: "Non-refundable, paid in full today",
            Total: String(Math.round(stay * 0.82)), Saving: String(Math.round(stay * 0.18)),
            CancellationText: "Non-refundable",
            PolicyText: "Full payment at booking. Non-refundable and non-amendable under any circumstances."
          });
          if (nights >= 7) {
            plans.push({
              Id: "weekly", Name: "Stay 7, pay 6", Description: "Minimum 7 nights",
              Total: String(Math.round(perNight * (nights - 1))), Saving: String(perNight), MinNights: 7,
              CancellationText: "Free changes 15+ days out",
              PolicyText: "One night free on stays of seven nights or longer. Standard deposit and cancellation terms."
            });
          }
          return {
            Id: slug,
            Name: rt.name,
            RoomImages: mockRoomImages(rt.name, i),
            MaxOccupancy: rt.maxOccupancy,
            Bedrooms: isSite ? 0 : (slug.indexOf("three") > -1 ? 3 : slug.indexOf("two") > -1 ? 2 : 1),
            Cars: 1,
            IsSite: isSite,
            RoomsLeft: ok ? [1, 2, 4, 3, 1, 5, 2, 6][i % 8] : 0,
            MinNights: typeMin,
            MaxNights: 21,
            Available: ok,
            Restriction: ok ? null
              : minFail ? { Code: "MIN_STAY", MinNights: typeMin, Message: typeMin + " night minimum on these dates" }
              : ctaFail ? { Code: "CLOSED_TO_ARRIVAL", Message: "No Saturday arrivals in snow season" }
              : maxFail ? { Code: "MAX_STAY", MaxNights: 21, Message: "Maximum 21 nights per booking" }
              : guests > rt.maxOccupancy ? { Code: "OCCUPANCY", Max: rt.maxOccupancy, Message: "Sleeps up to " + rt.maxOccupancy }
              : { Code: "SOLD_OUT", Message: "Booked out for these dates" },
            RatePlans: ok ? plans : []
          };
        })
      }]
    };
  }

  if (path === "/bestavailablerates") {
    const slug = params.roomType && ROOM_TYPES[params.roomType] ? params.roomType : "two-bedroom-chalet";
    const days = {};
    const from = new Date(params.fromDate), to = new Date(params.toDate);
    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
      const iso = d.toISOString().slice(0, 10);
      const month = d.getMonth() + 1;
      const dow = d.getDay();
      const snow = month >= 6 && month <= 9;
      const xmas = (month === 12 && d.getDate() >= 20) || (month === 1 && d.getDate() <= 5);
      const soldOut = d.getDate() % 17 === 0;
      const seed = (d.getDate() * 7 + d.getMonth() * 3 + slug.length) % 9;
      days[iso] = {
        Rate: String(mockRateFor(slug, iso)),
        Available: soldOut ? 0 : (snowSeed(seed) ),
        Closed: soldOut,
        MinStay: xmas ? 3 : (dow === 5 || dow === 6) ? 2 : 1,
        MaxStay: 21,
        ClosedToArrival: snow && dow === 6,
        ClosedToDeparture: xmas && dow === 6
      };
    }
    return { Properties: [{ PropertyId: "mock-property", PropertyName: "Kosciuszko Tourist Park", RoomTypeId: params.roomType || null, Days: days }] };
  }

  if (path.indexOf("/promocodes/") === 0) {
    const code = decodeURIComponent(path.split("/promocodes/")[1] || "").toUpperCase();
    return code === "SNOW26"
      ? [{ Code: code, Name: "Early snow season", Description: "10% off stays of 3 nights or more", Valid: true }]
      : [];
  }

  if (path === "/extras") {
    const nights = body && body.RoomStays && body.RoomStays[0]
      ? nightsBetween(body.RoomStays[0].Arrival, body.RoomStays[0].Departure) : 1;
    return [
      { Id: "drying-room", Name: "Drying room access", Description: "Per person, per night — applies to the full stay", UnitPrice: "5", Nights: nights, PerPerson: true },
      { Id: "extra-vehicle", Name: "Additional vehicle", Description: "Per night, registered at reception", UnitPrice: "20", Nights: nights },
      { Id: "firewood", Name: "Premium seasoned firewood", Description: "Per bag, collected from reception", UnitPrice: "20", Nights: 1 },
      { Id: "ev-charging", Name: "EV charging surcharge", Description: "Required to charge from your site supply", UnitPrice: "25", Nights: 1 },
      { Id: "early-checkin", Name: "Early check-in (before 1pm)", UnitPrice: "30", Nights: 1 },
      { Id: "late-checkout", Name: "Late checkout (until midday)", UnitPrice: "30", Nights: 1 }
    ];
  }

  if (path === "/beprofilefields") {
    return [
      { ExternalId: "vehicle-rego", Name: "Vehicle registration", FieldType: "text", AppliesTo: "reservation", Required: true },
      { ExternalId: "vehicle-make", Name: "Vehicle make and model", FieldType: "text", AppliesTo: "reservation", Required: true },
      { ExternalId: "arrival-time", Name: "Estimated arrival time", FieldType: "text", AppliesTo: "reservation", Required: false },
      { ExternalId: "how-heard", Name: "How did you hear about us?", FieldType: "lookup", AppliesTo: "person", Required: false,
        ProfileFieldValues: [{ ExternalId: "returning", Value: "Stayed before" }, { ExternalId: "search", Value: "Google" }, { ExternalId: "social", Value: "Social media" }, { ExternalId: "friend", Value: "Word of mouth" }] }
    ];
  }

  if (path === "/reservations") {
    const stays = (body && body.RoomStays) || [];
    const total = stays.reduce((sum, s) => sum + parseFloat(s.RoomTotal || "0"), 0);
    const deposit = Math.max(total * 0.5, total / Math.max(1, nightsBetween(stays[0] && stays[0].Arrival, stays[0] && stays[0].Departure)));
    return {
      ID: 90210,
      ConfNum: body && body.ConfNum ? body.ConfNum : "KTP-" + Math.floor(10000 + Math.random() * 89999),
      Status: (body && body.Status) || "Booked",
      CurrencyCode: "AUD",
      VerificationCode: method === "PATCH" ? "mock-verification-" + Date.now() : undefined,
      ReservationTotalAfterTax: total.toFixed(2),
      DepositAmount: deposit.toFixed(2),
      Surcharge: (total * 0.02).toFixed(2),
      RoomStays: stays,
      Guests: (body && body.Guests) || []
    };
  }

  return {};
}

/* ---------- Console helper ----------
 * Exposed only in the browser, only for wiring things up. Nothing on the site
 * reads it, so it can be deleted once the integration is signed off.
 *
 *   __gp.setMock(false)   force live data on localhost
 *   __gp.map()            slug -> GuestPoint id, as discovered so far
 *   __gp.content()        property description, rating, coords and photos
 */
if (typeof window !== "undefined") {
  window.__gp = {
    CONFIG,
    setMock(on) { CONFIG.mock = !!on; clearCache(); return CONFIG; },
    map: roomTypeMap,
    content: getPropertyContent,
    images: imagesFor,
    clearCache
  };
}
