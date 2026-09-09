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
  const live = e => Date.now() - e.ts < (e.sample ? Math.min(ttl, SAMPLE_TTL) : ttl);
  const hit = memory.get(key);
  if (hit && live(hit)) return hit.value;
  try {
    const raw = localStorage.getItem(LS_PREFIX + key);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (live(parsed)) {
        memory.set(key, parsed);
        return parsed.value;
      }
      localStorage.removeItem(LS_PREFIX + key);
    }
  } catch (e) {}
  return null;
}

/**
 * Sample data must not be cached for the real TTL. beprofilefields is a 24-hour
 * bucket, so a visitor who loaded the site before the API key was installed
 * would keep seeing invented rates and placeholder photos for a day after it
 * went live. Fixtures get a minute; real answers keep their full lifetime.
 */
const SAMPLE_TTL = 60 * 1000;

function cacheSet(key, value) {
  const entry = { value, ts: Date.now(), sample: notConfigured || CONFIG.mock };
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

/* ---------- Extras, as the Booking Engine actually returns them ----------
 *
 * The checkout was reading UnitPrice / PerPerson / Nights off each extra.
 * None of those exist in the spec — they were invented by our own mock, so
 * every extra would have priced at NaN the moment a real key was plugged in.
 *
 * BookingExtraOutput really carries:
 *   PriceType     perNight | perBooking | perPerson | perPersonPerNight | perRoom
 *   CheckoutType  service (taken once or not at all) | quantity (choose how many)
 *   MaxItems      0 means no limit
 *   Prices[]      { Id, Name, Price } — per-guest-type for the perPerson kinds,
 *                 a single unnamed entry for the rest
 *   RatePlans[]   the rate plans this extra is offered on
 *
 * This turns that into something a page can render and total without knowing
 * any of it, and tolerates the older mock shape so nothing breaks mid-change.
 */

const PER_PERSON_TYPES = ["perPerson", "perPersonPerNight"];
const PER_NIGHT_TYPES = ["perNight", "perPersonPerNight"];

function unitLabelFor(priceType) {
  switch (priceType) {
    case "perPersonPerNight": return "per person, per night";
    case "perPerson":         return "per person";
    case "perNight":          return "per night";
    case "perRoom":           return "per room";
    default:                  return "each";      // perBooking
  }
}

/**
 * @param {Array} list      raw /extras payload
 * @param {object} ctx      { nights, adults, children, ratePlanId }
 * @returns {Array} one entry per extra, priced and ready to render
 */
export function normaliseExtras(list, ctx) {
  const c = ctx || {};
  const nights = Math.max(1, Number(c.nights) || 1);
  const adults = Math.max(0, Number(c.adults) || 0);
  const children = Math.max(0, Number(c.children) || 0);

  return (Array.isArray(list) ? list : [])
    // An extra is only on offer for the rate plans it lists. No list means all.
    .filter(x => {
      if (!c.ratePlanId || !Array.isArray(x.RatePlans) || !x.RatePlans.length) return true;
      return x.RatePlans.indexOf(c.ratePlanId) > -1;
    })
    .map(x => {
      // Fall back to the old mock keys so a stale payload still prices.
      const priceType = x.PriceType || (x.PerPerson ? "perPersonPerNight"
                                      : Number(x.Nights) > 1 ? "perNight" : "perBooking");
      const rawPrices = Array.isArray(x.Prices) && x.Prices.length
        ? x.Prices
        : [{ Id: 0, Name: "", Price: x.UnitPrice }];

      const perPerson = PER_PERSON_TYPES.indexOf(priceType) > -1;
      const perNight = PER_NIGHT_TYPES.indexOf(priceType) > -1;

      // "0 means no limit" is not the same as "offer them fifty". Cap a
      // per-person extra at the party size and anything else at something a
      // guest could plausibly want, so the stepper has a real ceiling.
      const declaredMax = Number(x.MaxItems) || 0;
      const naturalMax = perPerson ? Math.max(1, adults + children) : 10;
      const max = x.CheckoutType === "service" ? 1
                : declaredMax > 0 ? declaredMax
                : naturalMax;

      const prices = rawPrices.map((pr, i) => {
        const name = (pr.Name || "").trim();
        // The per-guest-type prices arrive named Adult / Child. Default each
        // line's suggested count to how many of that type are actually staying.
        const suggested = !perPerson ? 0
          : /child/i.test(name) ? children
          : /adult/i.test(name) ? adults
          : adults + children;
        return {
          id: String(pr.Id !== undefined ? pr.Id : i),
          name,
          price: num(pr.Price) || 0,
          suggested,
          max: perPerson ? Math.max(1, suggested || adults + children) : max
        };
      });

      const single = prices.length === 1 && !prices[0].name;

      return {
        id: x.Id,
        name: x.Name,
        description: x.Description || "",
        image: (Array.isArray(x.Images) && x.Images[0] && x.Images[0].URL) || "",
        priceType,
        checkoutType: x.CheckoutType || "quantity",
        isService: x.CheckoutType === "service",
        perPerson,
        perNight,
        max,
        prices,
        /** One price and no guest types — the page can show a single stepper. */
        single,
        unitPrice: single ? prices[0].price : 0,
        unitLabel: unitLabelFor(priceType),
        displayOrder: Number(x.DisplayOrder) || 0
      };
    })
    .sort((a, b) => a.displayOrder - b.displayOrder);
}

/**
 * What a chosen extra costs.
 * @param {object} extra   an entry from normaliseExtras
 * @param {object} counts  { [priceId]: n } — for a single-price extra, { "0": n }
 * @param {number} nights
 */
export function extraCost(extra, counts, nights) {
  if (!extra) return 0;
  const n = extra.perNight ? Math.max(1, Number(nights) || 1) : 1;
  return extra.prices.reduce((sum, pr) => {
    const qty = Math.max(0, Number((counts || {})[pr.id]) || 0);
    return sum + pr.price * qty * n;
  }, 0);
}

/** Total for every chosen extra. `chosen` is { [extraId]: { [priceId]: n } }. */
export function extrasTotal(extras, chosen, nights) {
  return (extras || []).reduce(
    (sum, x) => sum + extraCost(x, (chosen || {})[x.id], nights), 0);
}

/** The Extras[] shape a room stay carries back to GuestPoint. */
export function extrasForBooking(extras, chosen) {
  const out = [];
  (extras || []).forEach(x => {
    const counts = (chosen || {})[x.id] || {};
    const entries = x.prices
      .map(pr => ({ PriceId: Number(pr.id), Count: Math.max(0, Number(counts[pr.id]) || 0) }))
      .filter(e => e.Count > 0);
    if (entries.length) out.push({ Id: x.id, Counts: entries });
  });
  return out;
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
 * GuestPoint's native manage call expects confirmation number + email +
 * surname. Kosipark deliberately asks for confirmation number + mobile +
 * surname instead. Our proxy verifies those three values against the Core API,
 * then supplies the stored email to the Booking Engine manage endpoint. The
 * browser never receives or invents the email used for that upstream login.
 *
 * The response carries a `Login` object saying which actions this property
 * actually permits (Cancel, PayNow, UpdateCreditCard, ...). Read it and hide
 * what is not allowed — do not assume.
 */

/** Australian-friendly canonical form used for comparison, never display. */
export function normaliseMobile(value) {
  let digits = String(value || "").replace(/\D/g, "");
  if (digits.indexOf("0011") === 0) digits = digits.slice(4);
  if (digits.indexOf("04") === 0) digits = "61" + digits.slice(1);
  if (digits.indexOf("4") === 0 && digits.length === 9) digits = "61" + digits;
  return digits;
}

/** Start the email verification step without returning any booking data. */
export function requestPortalOtp({ confNum, mobile, surname }) {
  return request("POST", "/portal/otp/request", {
    body: { ConfNum: confNum, Mobile: normaliseMobile(mobile), Surname: surname }
  });
}

/** Complete email verification; only this response may contain the booking. */
export function verifyPortalOtp({ challengeId, code }) {
  return request("POST", "/portal/otp/verify", {
    body: { ChallengeId: challengeId, Code: String(code || "").replace(/\D/g, "").slice(0, 6) }
  });
}

/** Turn ReservationManageOutput into the one shape every portal screen uses. */
export function normaliseManagedReservation(payload) {
  const root = payload && payload.Reservation ? payload : { Reservation: payload || {}, Login: {} };
  const r = root.Reservation || {};
  const allStays = Array.isArray(r.RoomStays) ? r.RoomStays : [];
  const activeStays = allStays.filter(x => !x.IsCancelled);
  // Keep the accommodation and dates visible when the whole reservation is
  // cancelled; on mixed bookings, continue to show only the active stays.
  const stays = activeStays.length ? activeStays : allStays;
  const arrivals = stays.map(x => x.Arrival).filter(Boolean).sort();
  const departures = stays.map(x => x.Departure).filter(Boolean).sort();
  const total = Number(r.ReservationTotalAfterTax || r.ReservationTotal || 0);
  const balance = Number(r.PaymentRequired || r.PayLater || 0);
  const rooms = [...new Set(stays.map(x => x.RoomTypeName).filter(Boolean))];
  const rateNames = [...new Set(stays.flatMap(x => (x.RateDetails || []).map(y => y.RatePlanName)).filter(Boolean))];
  const firstStay = stays[0] || {};
  const firstRate = (firstStay.RateDetails || [])[0] || {};
  return {
    id: r.ID,
    ref: r.ConfNum || "",
    status: r.Status || "Booked",
    room: rooms.join(" + ") || "Accommodation booking",
    checkIn: arrivals[0] || "",
    checkOut: departures[departures.length - 1] || "",
    adults: Number(r.Adults || stays.reduce((n, x) => n + Number(x.Adults || 0), 0)),
    children: Number(r.Children || stays.reduce((n, x) => n + Number(x.Children || 0), 0)),
    infants: Number(r.Infants || stays.reduce((n, x) => n + Number(x.Infants || 0), 0)),
    total,
    balance,
    paid: Math.max(0, total - balance),
    nightly: stays.length ? Number(stays[0].RoomTotal || 0) / Math.max(1, nightsBetween(stays[0].Arrival, stays[0].Departure)) : 0,
    roomTypeId: firstStay.RoomTypeId || "",
    ratePlanId: firstRate.RatePlanId || "",
    stayCount: stays.length,
    rate: rateNames.join(" + ") || "Booked rate",
    policyText: stays.map(x => x.PolicyText).filter(Boolean).join(" "),
    specialRequest: r.ExtraInfo || "",
    estimatedArrival: r.EstimatedArrival || "",
    channel: r.ChannelCode || r.SalesChannelCode || "",
    guests: Array.isArray(r.Guests) ? r.Guests : [],
    bookingContact: r.BookingContact || null,
    permissions: { ViewReservation: true, ...(root.Login || {}) },
    paymentDetails: root.PaymentDetails || null,
    paymentUrl: root.PaymentDetails && root.PaymentDetails.Session ? (root.PaymentDetails.Session.PayUrl || "") : "",
    sample: isUnconfigured(),
    portalToken: root.PortalToken || "",
    raw: r
  };
}

/**
 * Check a proposed date change against live GuestPoint inventory while keeping
 * the booking on its original room type and rate plan. This deliberately only
 * quotes the change: PartialUpdateInput does not accept Arrival/Departure, so
 * sending dates to the manage PATCH would be ignored by GuestPoint.
 */
export async function quoteManagedStayChange(booking, proposal) {
  if (!booking || !proposal || !proposal.checkIn || !proposal.checkOut) {
    throw new Error("Choose new check-in and check-out dates.");
  }
  if (Number(booking.stayCount || 0) !== 1) {
    return { available: false, code: "MULTI_STAY", message: "Bookings with more than one accommodation must be changed by reception." };
  }

  const data = await searchAvailability({
    arrivalDate: proposal.checkIn,
    departureDate: proposal.checkOut,
    numAdults: proposal.adults || booking.adults || 1,
    numChildren: proposal.children || booking.children || 0,
    roomTypes: booking.roomTypeId || undefined
  });
  const rows = flattenAvailability(data);
  const wantedId = String(booking.roomTypeId || "");
  const wantedName = String(booking.room || "").trim().toLowerCase();
  const room = rows.find(x => String(x.gpRoomTypeId || "") === wantedId)
    || rows.find(x => String(x.name || "").trim().toLowerCase() === wantedName);

  if (!room || !room.available) {
    return { available: false, code: "UNAVAILABLE", message: (room && room.restriction && room.restriction.message) || "Your current accommodation is not available for those dates." };
  }
  const proposedGuests = Number(proposal.adults || 0) + Number(proposal.children || 0) + Number(proposal.infants || 0);
  if (Number(room.maxOccupancy || 0) > 0 && proposedGuests > Number(room.maxOccupancy)) {
    return { available: false, code: "MAX_OCCUPANCY", maxOccupancy: Number(room.maxOccupancy), message: "This accommodation allows a maximum of " + room.maxOccupancy + " guests, including infants." };
  }

  const wantedPlan = String(booking.ratePlanId || "");
  const wantedRate = String(booking.rate || "").trim().toLowerCase();
  const plan = (room.plans || []).find(x => wantedPlan && String(x.ratePlanId || "") === wantedPlan)
    || (room.plans || []).find(x => String(x.name || "").trim().toLowerCase() === wantedRate);
  if (!plan || plan.closed) {
    return { available: false, code: "RATE_UNAVAILABLE", message: "The accommodation is available, but your original rate plan is not available for those dates." };
  }

  const newTotal = Number(plan.total || 0);
  const difference = newTotal - Number(booking.total || 0);
  return {
    available: true,
    room: room.name,
    rate: plan.name,
    newTotal,
    difference,
    maxOccupancy: room.maxOccupancy,
    roomsLeft: room.roomsLeft,
    message: "Live availability is confirmed, but the booking has not been changed because GuestPoint's documented management API does not accept new stay dates. " + (difference < 0
      ? "The original cancellation terms still apply to the reduction."
      : difference > 0
        ? "The additional amount would need to be paid before the change is completed."
        : "There would be no change to the accommodation total.")
  };
}

/**
 * 9. PATCH /reservations/manage/{confNum} — partial update.
 *
 * Two traps worth remembering: `Guests` replaces the entire guest list, so send
 * every guest and not just the changed one; and a reservation cannot be
 * modified once any room stay's arrival date is in the past.
 */
export function modifyReservation(confNum, changes, portalToken, notify = false) {
  return request("POST", "/portal/update", {
    body: { ConfNum: confNum, Changes: changes, PortalToken: portalToken, Notify: !!notify }
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

/* The cart used to lapse after ten minutes, counted from the OLDEST item.
   That is what made a second stay look like it never added: pick a cabin,
   read a room page, choose dates on a chalet — eleven ordinary minutes — and
   the first stay was quietly binned by the read that ran just before the
   second was pushed. The count stayed at one, so the new stay looked lost.

   Nothing is reserved by sitting in this cart, so there was never a reason to
   throw the guest's work away that fast. It now keeps a selection for a day,
   and every item ages on its own clock rather than inheriting the oldest. */
export const CART_TTL = 24 * 60 * 60 * 1000;

/** Rates move. Past this, checkout re-quotes rather than trusting what's stored. */
export const CART_PRICE_TTL = 30 * 60 * 1000;

/** The checkout and header use one window for an active, bookable quote. */
export const CHECKOUT_QUOTE_TTL = 15 * 60 * 1000;

/** Time left before the oldest quoted stay must be searched again. */
export function cartQuoteExpiresIn() {
  const items = readCart();
  if (!items.length) return 0;
  const oldestQuote = Math.min.apply(null, items.map(i => i.pricedAt || i.addedAt || 0));
  return Math.max(0, CHECKOUT_QUOTE_TTL - (Date.now() - oldestQuote));
}

/** Time until the next item lapses — the honest answer to "when does this go?". */
export function cartExpiresIn() {
  const items = readCart();
  if (!items.length) return 0;
  const oldest = Math.min.apply(null, items.map(i => i.addedAt || 0));
  return Math.max(0, CART_TTL - (Date.now() - oldest));
}

/** True when any stored price is old enough that it must be re-quoted. */
export function cartPricingStale() {
  const now = Date.now();
  return readCart().some(i => now - (i.pricedAt || i.addedAt || 0) > CART_PRICE_TTL);
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

let cartIdSequence = 0;
/**
 * One id per deliberate selection. The id travels to checkout in the URL, so
 * reloading that URL is idempotent while choosing the same room again creates
 * a genuinely separate unit in the booking.
 */
export function newCartItemId() {
  const random = typeof globalThis !== "undefined" && globalThis.crypto && typeof globalThis.crypto.randomUUID === "function"
    ? globalThis.crypto.randomUUID()
    : Math.random().toString(36).slice(2);
  return "stay:" + Date.now().toString(36) + ":" + (cartIdSequence++).toString(36) + ":" + random;
}

export function addToCart(item) {
  const now = Date.now();
  // Read the raw store, not readCart(): adding a stay must never be the thing
  // that expires an earlier one. A guest choosing a second cabin is the most
  // engaged they will ever be — that is the worst possible moment to prune.
  let items;
  try {
    const parsed = JSON.parse(localStorage.getItem(CART_KEY) || "[]");
    items = Array.isArray(parsed) ? parsed.filter(i => Number(i.total) > 0) : [];
  } catch (e) { items = []; }

  const id = item.id || (item.roomTypeId + ":" + item.arrival + ":" + item.departure + ":" + now);
  // A supplied id identifies one click/selection and makes checkout reloads
  // safe. Legacy callers without an id keep the old same-stay protection.
  const exists = item.id
    ? items.some(i => i.id === item.id)
    : items.some(i => i.roomTypeId === item.roomTypeId && i.arrival === item.arrival && i.departure === item.departure);
  if (exists) return readCart();
  items.push({ ...item, id, addedAt: now, pricedAt: item.pricedAt || now });
  // Still browsing, so the whole selection stays alive together rather than
  // one stay outliving the others for no reason the guest can see.
  const refreshed = items.map(i => ({ ...i, addedAt: Math.max(i.addedAt || 0, now - CART_PRICE_TTL) }));
  writeCart(refreshed);
  return readCart();
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

/**
 * Per-night availability for ONE room type, with real counts.
 *
 * The calendars used to read /bestavailablerates, which answers only
 * "RoomAvailable: true/false" — no numbers — so the "1 left" badge the UI
 * already knew how to draw could never fire. /availabilities carries
 * Availabilities[] per room type: one entry per night with Closed and an
 * integer ForSale. That is the count, and it is specific to the category
 * asked for, which is the point: three chalets left says nothing about
 * whether a powered site is free.
 *
 * calendarMode asks GuestPoint to include closed and sold-out dates too,
 * otherwise the sold-out nights simply vanish from the response and the grid
 * cannot tell "full" from "not returned".
 *
 * Returns the same shape as normaliseDays so the calendars are unchanged
 * apart from which call they make.
 */
export async function availabilityCalendar({ room, fromDate, toDate, numAdults = 2, numChildren = 0 }) {
  const data = await request("GET", "/availabilities", {
    params: {
      arrivalDate: fromDate, departureDate: toDate,
      numAdults, numChildren, calendarMode: true,
      roomTypes: toRoomTypeIds(room)
    },
    cacheKind: "availabilities"
  });

  const prop = data && data.Properties && data.Properties[0];
  if (!prop) return {};
  learnRoomTypes(prop.RoomTypes);

  // Scoping by roomTypes should return one type, but never trust that.
  const wantId = roomTypeIdFor(room) || room;
  const rt = (prop.RoomTypes || []).find(
    r => (r.Id || r.RoomTypeId) === wantId || slugForRoomType(r) === room
  ) || (prop.RoomTypes || [])[0];
  if (!rt) return {};

  const out = {};

  // Counts and closures, per night.
  (rt.Availabilities || []).forEach(a => {
    if (!a || !a.Date) return;
    const forSale = typeof a.ForSale === "number" ? a.ForSale : null;
    out[a.Date] = {
      rate: null, strikeRate: null, discountReason: "",
      count: forSale,
      soldOut: a.Closed === true || forSale === 0,
      closed: a.Closed === true || forSale === 0,
      minStay: null, maxStay: null,
      closedToArrival: false, closedToDeparture: false
    };
  });

  // The cheapest plan's nightly rate and restrictions, merged on top.
  //
  // Only plans that actually carry Rates are eligible. Sorting on a summed
  // total puts a plan with no rates at zero — the cheapest thing in the list
  // — and the calendar then shows no prices at all while looking like it
  // worked. Filter first, sort second.
  const priced = (rt.RatePlans || []).filter(p => Array.isArray(p.Rates) && p.Rates.length);
  const total = p => p.Rates.reduce((sum, x) => sum + num(x.SellRate), 0);
  const cheapest = priced.slice().sort((a, b) => total(a) - total(b))[0];
  (cheapest && cheapest.Rates ? cheapest.Rates : []).forEach(r => {
    if (!r || !r.Date) return;
    const day = out[r.Date] || (out[r.Date] = { count: null, soldOut: false, closed: false });
    const sell = num(r.SellRate);
    day.rate = sell > 0 ? sell : null;
    day.strikeRate = r.StrikeOutRate !== undefined ? num(r.StrikeOutRate) : null;
    day.discountReason = r.DiscountReason || "";
    day.minStay = num(r.MinStayArrival) || day.minStay || null;
    day.closedToArrival = r.ClosedToArrival === true;
    day.closedToDeparture = r.ClosedToDeparture === true;
    if (r.Closed === true) { day.soldOut = true; day.closed = true; }
  });

  /* A calendar with counts but no prices renders as a solid wall of "Sold
     out", which is the worst possible lie to tell a guest looking for a
     free night. It has happened twice already — once from rate plans sorted
     on an empty Rates array, once from a month-long window tripping a
     stay-length rule — so rather than trust the availabilities payload to
     always carry rates, fall back to the endpoint whose whole job is the
     per-night price. */
  const anyPriced = Object.keys(out).some(d => typeof out[d].rate === "number");
  if (!anyPriced && Object.keys(out).length) {
    try {
      const bar = await bestAvailableRates({ fromDate, toDate, roomType: room, numAdults, numChildren });
      const days = normaliseDays(bar, room);
      Object.keys(out).forEach(date => {
        const d = days[date];
        if (!d || typeof d.rate !== "number") return;
        out[date].rate = d.rate;
        if (out[date].minStay == null) out[date].minStay = d.minStay;
        // Availability still comes from /availabilities, which is the endpoint
        // that actually counts rooms. Only the price is borrowed.
        if (d.soldOut === false && out[date].count === null) out[date].soldOut = false;
      });
    } catch (e) { /* leave the calendar as it was; it is still readable */ }
  }

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
    // Whatever the property calls it is what every page must call it.
    rememberRoomName(slug, rt.Name || rt.RoomTypeName);
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

/* The pages used to keep their own slug-to-name tables. They drifted: the
   results card offered an "Unpowered Site" and the checkout put an "Unpowered
   Tent Site" in the booking, so a guest could not tell whether the thing they
   picked was the thing they got. Worse, a hardcoded table is wrong by
   definition once real data arrives — GuestPoint names these types, not us.
   One function, and the live name wins. */
const LEARNED_NAMES = new Map();
export function rememberRoomName(slug, name) {
  if (slug && name) LEARNED_NAMES.set(slug, String(name));
}
export function nameForRoom(slug) {
  if (!slug) return "";
  return LEARNED_NAMES.get(slug)
      || (ROOM_TYPES[slug] && ROOM_TYPES[slug].name)
      || String(slug).replace(/-/g, " ").replace(/\b\w/g, c => c.toUpperCase());
}

/** Is this exact selection already in the booking? Returns it, or null. */
export function cartHas({ id, roomTypeId, arrival, departure }) {
  return readCart().find(i => id
    ? i.id === id
    : i.roomTypeId === roomTypeId && i.arrival === arrival && i.departure === departure) || null;
}

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

  // Fixture photos exist to prove the pipeline in local development. On a real
  // host serving sample data because the key is missing, they would put
  // "GuestPoint photo 1" in front of a guest — so drop them there and let
  // gp-images draw its captioned panels instead, which say what shot belongs
  // in each slot. Explicit local mock keeps the fixtures.
  const sampleOnLiveHost = notConfigured && !CONFIG.mock;

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
    images: sampleOnLiveHost ? [] : normaliseImages(prop.PropertyImages),
    rooms: sampleOnLiveHost ? {} : Object.fromEntries(Array.from(IMAGES_BY_SLUG.entries())),
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
/* Sample photography.
 *
 * These used to be a flat colour block with the scene name set in 46px across
 * the middle. On a card that read as a label; stretched across a 1440px hero
 * it became two lines of grey text over the top of the home page, and the
 * first thing anyone saw was placeholder furniture. Since every photo on the
 * site is one of these until a GuestPoint key is in place, that was the site.
 *
 * They are drawn as landscapes now — a sky, a ridgeline, a treeline — with no
 * text at all. The page already carries a plain banner saying the data is
 * sample, so the pictures do not need to shout it, and a quiet mountain
 * silhouette is honest about being a drawing rather than pretending to be a
 * photograph of a cabin nobody has stayed in.
 */
function mockImage(label, tone, seq) {
  // Deterministic per scene, so a given slot looks the same on every reload.
  let h = 0;
  for (let i = 0; i < label.length; i++) h = (h * 31 + label.charCodeAt(i)) | 0;
  h = Math.abs(h) + seq * 7;

  const SKIES = [
    ["#26404A", "#4C6B6B"], ["#1D3730", "#3F5A4A"], ["#3A3B4E", "#6B6A78"],
    ["#5B4630", "#96784E"], ["#22323F", "#57707A"], ["#122720", "#33493C"]
  ];
  const sky = SKIES[h % SKIES.length];
  const far = ["#4A5D57", "#55665C", "#5E6E66"][h % 3];
  const mid = ["#33463E", "#2C3F38", "#3A4E43"][(h >> 2) % 3];
  const near = ["#1C2C26", "#17251F", "#20302A"][(h >> 3) % 3];

  // A ridgeline built from the hash, so no two scenes share a skyline.
  const ridge = (base, amp, seed) => {
    const pts = [];
    for (let x = 0; x <= 1200; x += 100) {
      const n = Math.sin((x / 1200) * Math.PI * 2 * (1 + (seed % 3)) + seed) * amp;
      const n2 = Math.sin((x / 1200) * Math.PI * 5 + seed * 1.7) * (amp / 3);
      pts.push(x + " " + Math.round(base + n + n2));
    }
    return "M0 800 L0 " + Math.round(base) + " L" + pts.join(" L") + " L1200 800 Z";
  };

  const orbY = 150 + (h % 90);
  const orbX = 200 + (h % 800);
  const isNight = (h % 5) === 0;

  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">' +
      '<defs><linearGradient id="s" x1="0" y1="0" x2="0" y2="1">' +
        '<stop offset="0" stop-color="' + sky[0] + '"/>' +
        '<stop offset="1" stop-color="' + sky[1] + '"/>' +
      '</linearGradient></defs>' +
      '<rect width="1200" height="800" fill="url(#s)"/>' +
      '<circle cx="' + orbX + '" cy="' + orbY + '" r="' + (isNight ? 26 : 44) + '" ' +
        'fill="' + (isNight ? "rgba(237,228,212,0.55)" : "rgba(252,244,224,0.30)") + '"/>' +
      '<path d="' + ridge(430, 60, h) + '" fill="' + far + '" opacity="0.85"/>' +
      '<path d="' + ridge(540, 46, h + 3) + '" fill="' + mid + '"/>' +
      '<path d="' + ridge(650, 30, h + 7) + '" fill="' + near + '"/>' +
      // A suggestion of snow gums along the near ridge.
      '<g fill="' + near + '" opacity="0.9">' +
        [0, 1, 2, 3, 4, 5].map(function (i) {
          const x = 90 + ((h * (i + 3)) % 1050);
          const t = 34 + ((h + i * 13) % 40);
          return '<path d="M' + x + ' 800 L' + x + ' ' + (760 - t) +
                 ' M' + (x - 12) + ' ' + (772 - t) + ' L' + x + ' ' + (752 - t) +
                 ' L' + (x + 12) + ' ' + (772 - t) + '" stroke="' + near +
                 '" stroke-width="5" fill="none" stroke-linecap="round"/>';
        }).join("") +
      '</g>' +
    '</svg>';

  return {
    URL: "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg),
    Sequence: String(seq),
    Captions: { en: label }
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

const mockOtpChallenges = new Map();

async function mockResponse(method, path, params, body) {
  await new Promise(r => setTimeout(r, 220));

  if (path === "/portal/otp/request") {
    const challengeId = "mock-" + Math.random().toString(36).slice(2);
    mockOtpChallenges.set(challengeId, { ...(body || {}) });
    return {
      ChallengeId: challengeId,
      ExpiresIn: 600,
      DemoCode: "123456",
      Message: "If those details match a booking, a verification code has been sent to the email held on it."
    };
  }

  if (path === "/portal/otp/verify") {
    const challengeId = String(body && body.ChallengeId || "");
    const details = mockOtpChallenges.get(challengeId);
    if (!details || String(body && body.Code || "") !== "123456") {
      const err = new Error("That code is invalid or has expired.");
      err.status = 403;
      throw err;
    }
    mockOtpChallenges.delete(challengeId);
    return mockResponse("POST", "/portal/lookup", null, details);
  }

  if (path === "/portal/lookup") {
    const samples = [
      { ref: "1", surname: "1", mobile: "1", id: 1, roomType: "cedar-cabin",
        room: "Cedar Cabin", arrival: "2026-11-10", departure: "2026-11-13",
        adults: 2, children: 0, infants: 0, total: 525, paid: 262.5,
        rate: "Standard rate", policy: "Standard cancellation and amendment conditions apply." },
      { ref: "KTP-48213", surname: "Nguyen", mobile: "61412482130", id: 48213, roomType: "two-bedroom-chalet",
        room: "Two Bedroom Chalet", arrival: "2026-10-16", departure: "2026-10-19",
        adults: 2, children: 2, infants: 0, total: 612, paid: 306,
        rate: "Standard rate", policy: "Standard cancellation and amendment conditions apply." },
      { ref: "KTP-51907", surname: "Doyle", mobile: "61412519070", id: 51907, roomType: "caravan-site",
        room: "Caravan Site (powered)", arrival: "2026-12-27", departure: "2027-01-02",
        adults: 4, children: 2, infants: 1, total: 438, paid: 219,
        rate: "Standard rate", policy: "Standard cancellation and amendment conditions apply." },
      { ref: "KTP-52440", surname: "Reid", mobile: "61412524400", id: 52440, roomType: "cedar-cabin",
        room: "Cedar Cabin", arrival: "2026-09-19", departure: "2026-09-21",
        adults: 2, children: 0, infants: 0, total: 396, paid: 396,
        rate: "Promotional rate", policy: "This promotional rate is non-refundable and non-amendable." }
    ];
    const ref = String(body && body.ConfNum || "").trim().toUpperCase();
    const surname = String(body && body.Surname || "").trim().toLowerCase();
    const mobile = normaliseMobile(body && body.Mobile);
    const b = samples.find(x => x.ref === ref && x.surname.toLowerCase() === surname && x.mobile === mobile);
    if (!b) {
      const err = new Error("We couldn't match those booking details.");
      err.status = 404;
      throw err;
    }
    return {
      Reservation: {
        ID: b.id, ConfNum: b.ref, Status: "Booked", CurrencyCode: "AUD",
        ChannelCode: "KOSIPARK-DIRECT", Adults: b.adults, Children: b.children, Infants: b.infants,
        ReservationTotalAfterTax: String(b.total), PaymentRequired: String(b.total - b.paid),
        EstimatedArrival: "15:00", ExtraInfo: "",
        Guests: [{
          ID: "guest-" + b.id, FirstName: "Alex", LastName: b.surname,
          Email: "alex." + b.surname.toLowerCase() + "@example.com", Mobile: "+" + b.mobile,
          Phone: "+" + b.mobile, City: "", State: "NSW", PostalCode: "", Country: "AU", ProfileFields: []
        }],
        BookingContact: {
          ID: "guest-" + b.id, FirstName: "Alex", LastName: b.surname,
          Email: "alex." + b.surname.toLowerCase() + "@example.com", Mobile: "+" + b.mobile,
          Phone: "+" + b.mobile, City: "", State: "NSW", PostalCode: "", Country: "AU", ProfileFields: []
        },
        RoomStays: [{
          Arrival: b.arrival, Departure: b.departure, RoomTotal: String(b.total),
          RoomTypeId: b.roomType, RoomTypeName: b.room, Adults: b.adults, Children: b.children, Infants: b.infants,
          GuestId: "guest-" + b.id, GuestName: "Alex " + b.surname,
          IsCancelled: false, PolicyText: b.policy,
          RateDetails: [{ RatePlanId: b.rate === "Standard rate" ? "standard" : "advance", RatePlanName: b.rate, RoomRate: String(b.total) }], Extras: []
        }]
      },
      Login: {
        ViewReservation: true, UpdateGuestDetails: true, UpdateCreditCard: false,
        SpecialRequests: true, PayNow: b.total > b.paid, Cancel: true,
        UpdateStayDates: b.rate === "Standard rate", RequirePhone: true, RequireAddress: false
      },
      PortalToken: "mock-portal-token",
      PaymentDetails: b.total > b.paid ? { Gateway: "GuestPoint Pay", Session: { PayUrl: "https://payments.example.invalid/pay/" + b.ref } } : null,
      Message: ""
    };
  }

  if (path === "/portal/update") {
    return { Updated: true, NotificationSent: body && body.Notify ? true : null, GuestPoint: { Success: true } };
  }

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
          // calendarMode asks "what is each night doing?", not "book me this
          // stay". Judging a 30-night calendar window as a 30-night booking
          // tripped the max-stay rule and closed every night on the page —
          // the whole park showed as sold out for every month. Stay-level
          // rules apply to a proposed stay, never to a calendar sweep.
          const calendarMode = params.calendarMode === true || params.calendarMode === "true";
          const minFail = !calendarMode && nights < typeMin;
          const ctaFail = !calendarMode && arrMonth >= 6 && arrMonth <= 9 && arrDow === 6 && !isSite;
          const maxFail = !calendarMode && nights > 21;
          const ok = !closedOut && (calendarMode || guests <= rt.maxOccupancy) && !minFail && !ctaFail && !maxFail;
          const stay = perNight * nights;
          // Per-night arrays, so the calendars have something shaped like the
          // real payload to read: a count per night and a rate per night.
          const nightList = [];
          for (let n = 0; n < Math.max(1, nights); n++) {
            const d = new Date(params.arrivalDate);
            d.setDate(d.getDate() + n);
            nightList.push(d.toISOString().slice(0, 10));
          }
          // A deterministic count per night per type — low for some, so the
          // "1 left" badge is exercised, and stable across reloads.
          const forSaleOn = iso => {
            const seed = snowSeed((new Date(iso).getDate() + i) % 9);
            const m = new Date(iso).getMonth() + 1;
            const peak = m >= 6 && m <= 9;
            return closedOut ? 0 : Math.max(0, peak ? Math.min(seed, 3) - 1 : seed);
          };

          const ratesFor = factor => nightList.map(iso => {
            const d = new Date(iso);
            const m = d.getMonth() + 1, dow = d.getDay();
            const nightClosed = calendarMode
              ? (closedOut || d.getDate() % 17 === 0)
              : !ok;
            return {
              Date: iso,
              SellRate: String(Math.round(mockRateFor(slug, iso) * factor)),
              Closed: nightClosed,
              // Per-night restrictions in calendar mode, so the grid can show
              // which nights you may actually arrive on.
              ClosedToArrival: calendarMode
                ? (m >= 6 && m <= 9 && dow === 6 && !isSite)
                : (iso === params.arrivalDate ? ctaFail : false),
              ClosedToDeparture: false,
              MinStayArrival: calendarMode
                ? ((m >= 6 && m <= 9 && (dow === 5 || dow === 6)) ? 2 : 1)
                : (iso === params.arrivalDate ? typeMin : 0)
            };
          });

          const plans = [{
            Id: "standard", Name: "Standard rate", Description: "Our most flexible rate",
            Total: String(stay), Rates: ratesFor(1),
            CancellationText: "Free changes 15+ days out",
            PolicyText: "Deposit of 50% or one full night's tariff, whichever is higher. Cancellation fees apply within 14 days."
          }];
          const arrivalDow = new Date(params.arrivalDate).getDay();
          if (nights >= 2 && arrivalDow >= 1 && arrivalDow <= 3) {
            plans.push({
              Id: "midweek", Name: "Midweek saver", Description: "Arrive Mon–Wed and save 12%",
              Total: String(Math.round(stay * 0.88)), Saving: String(Math.round(stay * 0.12)),
              Rates: ratesFor(0.88),
              CancellationText: "Free changes 15+ days out",
              PolicyText: "Deposit of 50% or one night's tariff. Same cancellation terms as the standard rate."
            });
          }
          plans.push({
            Id: "advance", Name: "Book early, pay less", Description: "Non-refundable, paid in full today",
            Total: String(Math.round(stay * 0.82)), Saving: String(Math.round(stay * 0.18)),
            Rates: ratesFor(0.82),
            CancellationText: "Non-refundable",
            PolicyText: "Full payment at booking. Non-refundable and non-amendable under any circumstances."
          });
          if (nights >= 7) {
            plans.push({
              Id: "weekly", Name: "Stay 7, pay 6", Description: "Minimum 7 nights",
              Total: String(Math.round(perNight * (nights - 1))), Saving: String(perNight), MinNights: 7,
              Rates: ratesFor((nights - 1) / Math.max(1, nights)),
              CancellationText: "Free changes 15+ days out",
              PolicyText: "One night free on stays of seven nights or longer. Standard deposit and cancellation terms."
            });
          }
          return {
            Id: slug,
            Name: rt.name,
            RoomImages: mockRoomImages(rt.name, i),
            Availabilities: nightList.map(iso => {
              // In calendar mode each night stands on its own: a scattering of
              // genuinely full nights, not a blanket closure.
              const nightClosed = calendarMode
                ? (closedOut || new Date(iso).getDate() % 17 === 0)
                : !ok;
              return { Date: iso, Closed: nightClosed, ForSale: nightClosed ? 0 : forSaleOn(iso) };
            }),
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
    // Shaped exactly like BookingExtraOutput. It used to invent UnitPrice /
    // PerPerson / Nights, which meant the checkout was written against fields
    // the real Booking Engine has never returned — every extra would have
    // priced at NaN on the first live key. The sample data now lies about the
    // prices only, never about the shape.
    const one = (price) => [{ Id: 0, Name: "", Price: String(price) }];
    const perGuest = (adult, child) => [
      { Id: 0, Name: "Adult", Price: String(adult) },
      { Id: 1, Name: "Child", Price: String(child) }
    ];
    return [
      { Id: "drying-room", Name: "Drying room access",
        Description: "Somewhere warm for boots and jackets overnight.",
        ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 0,
        PriceType: "perPersonPerNight", DisplayOrder: 1, Images: null,
        Prices: perGuest(5, 3), RatePlans: [] },
      { Id: "firewood", Name: "Premium seasoned firewood",
        Description: "A bag of dry hardwood, collected from reception.",
        ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 6,
        PriceType: "perBooking", DisplayOrder: 2, Images: null,
        Prices: one(20), RatePlans: [] },
      { Id: "extra-vehicle", Name: "Additional vehicle",
        Description: "A second car on your site, registered at reception.",
        ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 3,
        PriceType: "perNight", DisplayOrder: 3, Images: null,
        Prices: one(20), RatePlans: [] },
      { Id: "linen-pack", Name: "Linen and towel pack",
        Description: "Made-up beds and bath towels, per person.",
        ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 0,
        PriceType: "perPerson", DisplayOrder: 4, Images: null,
        Prices: perGuest(35, 25), RatePlans: [] },
      { Id: "ev-charging", Name: "EV charging",
        Description: "Charge from your site supply for the stay.",
        ExtraType: "checkout", CheckoutType: "service", MaxItems: 1,
        PriceType: "perNight", DisplayOrder: 5, Images: null,
        Prices: one(25), RatePlans: [] },
      { Id: "early-checkin", Name: "Early check-in, from 11am",
        Description: "Subject to the cabin being ready.",
        ExtraType: "checkout", CheckoutType: "service", MaxItems: 1,
        PriceType: "perBooking", DisplayOrder: 6, Images: null,
        Prices: one(30), RatePlans: [] },
      { Id: "late-checkout", Name: "Late checkout, until midday",
        Description: "Two extra hours on your last morning.",
        ExtraType: "checkout", CheckoutType: "service", MaxItems: 1,
        PriceType: "perBooking", DisplayOrder: 7, Images: null,
        Prices: one(30), RatePlans: [] }
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
