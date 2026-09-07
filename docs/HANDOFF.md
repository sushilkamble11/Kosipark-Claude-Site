# Kosciuszko Tourist Park — booking site handoff

Direct booking site for Kosciuszko Tourist Park (Sawpit Creek, 1400 Kosciuszko Rd,
Jindabyne NSW). Plain HTML/CSS/JS — no build step, no framework install. Open any
`.html` file in a browser and it runs.

Contact details used throughout: 02 6456 2224 · stay@kosipark.com.au.

---

## 1. Files

### Pages (each one opens directly)

| File | URL role | What it does |
|---|---|---|
| `Kosipark.dc.html` | `/` | Home. Hero, search bar (`#book`), ways to stay, facilities, attractions, gallery, reviews, location. |
| `Accommodation.dc.html` | `/accommodation` | All 8 types with full detail, each with its own availability calendar. |
| `Room.dc.html?room=<slug>` | `/accommodation/<slug>` | One type in detail: copy, spec table, 3 photo slots, that type's calendar, related types. |
| `Availability.dc.html?arrival=&departure=&adults=…` | `/book` | Search results. Cards with galleries, scarcity, rate plans, restrictions. |
| `Calendar.dc.html?room=<slug>` | `/book/calendar` | Two-month availability + rates for one type, with restriction reasons. |
| `Checkout.dc.html` | `/book/checkout` | 3 steps: details → options → payment, then confirmation. Reads the cart. |
| `Manage.dc.html` | `/manage` | Booking lookup, change/cancel requests. **Mock data** (see §5). |
| `Terms.dc.html` | `/terms` | Full T&Cs, extracted from kosipark.com.au July 2026 + new clauses (§6). |
| `Attractions.dc.html`, `Gallery.dc.html`, `Contact.dc.html` | | Content pages. |

### Shared components (imported by the pages)

| File | Purpose | Key props |
|---|---|---|
| `SiteNav.dc.html` | Fixed header, nav, live weather pill, cart button + panel, mobile menu | `active`, `bookHref`, `stickyBookBar`, `liveWeather`, `temperature`, `weather` |
| `SiteFooter.dc.html` | Footer | — |
| `BookingBar.dc.html` | Date/guest search bar with custom calendar | `compact`, `submitLabel`, `onSubmit`, `action`, `room`, `initialArrival`, `initialDeparture`, `initialAdults`, `maxGuests` |
| `TypeCalendar.dc.html` | Per-type availability calendar (green/red/selected + "1 left") | `room`, `label`, `months`, `adults` |

`support.js` is the runtime — do not edit. `image-slot.js` powers the photo
placeholders. `assets/kosipark-logo.png` is the supplied logo.

**Attribute naming:** props are kebab-case on a mount (`book-href`, `sticky-book-bar`,
`initial-arrival`). `name` is reserved by the component loader — never pass a prop
called `name` (this caused a real bug; `TypeCalendar` uses `label` instead).

---

## 2. The API layer — `guestpoint.js`

Every API call goes through this one file. **It never holds the API key.**

```js
export const CONFIG = {
  baseUrl: "/api/gp",                    // YOUR proxy, not beapi.guestpoint.dev
  propertyId: "REPLACE_WITH_PROPERTY_ID",
  mock: true,                            // false to go live
  currency: "AUD"
};
```

### To go live

1. Stand up a proxy (Cloudflare Worker or your middleware) that adds the
   `X-API-KEY` header and forwards to `https://beapi.guestpoint.dev/api/v1`.
   The site calls `{baseUrl}/properties/{propertyId}/…`; the proxy adds the key.
2. Set `baseUrl`, `propertyId`, and `mock: false`.
3. Fill `ROOM_TYPES` — each of the 8 slugs needs its GuestPoint `RoomTypeId`.
   All 8 are currently `id: ""`. Get them from one `/availabilities` response.

### Endpoints wired

| Function | Endpoint | Browser cache |
|---|---|---|
| `searchAvailability` | `GET /availabilities` | 5 min |
| `bestAvailableRates` | `GET /bestavailablerates` | 15 min |
| `checkPromoCode` | `GET /promocodes/{code}` | 10 min |
| `getExtras` | `POST /extras` | 15 min |
| `getProfileFields` | `GET /beprofilefields` | 24 hr |
| `validateReservation` | `PATCH /reservations` | never |
| `createReservation` | `POST /reservations` | never |
| `modifyReservation` / `cancelReservation` | `POST /reservations` (Status Modified/Cancelled) | never |

Caching is memory + localStorage with a TTL per data type. **The proxy should
cache the same GETs at the edge** (5–15 min, `stale-while-revalidate`) — that's
what keeps GuestPoint call volume sane. Reservation calls are never cached.

### Not GuestPoint

- `captureLead(lead)` → posts to `LEAD_ENDPOINT` (currently `""`, so it only logs).
  Point it at your middleware to enable abandoned-booking follow-up. Fires when the
  details step is completed, before payment.
- Cart helpers: `readCart`, `addToCart`, `removeFromCart`, `clearCart`,
  `cartExpiresIn`. localStorage, 10-minute TTL (`CART_TTL`).

---

## 3. API capability tiers — read before changing booking UX

The design was tiered against what GuestPoint can actually do. Answers below come
from the Booking Engine API spec; two remain unknown and need a dev-key call.

| Question | Answer | Consequence in the design |
|---|---|---|
| Site/rig dimensions modelled? | **No** | **Tier C**: dimensions are captured, never filtered. Copy says "We'll allocate a site that fits. If nothing suits your dimensions we'll call you within one business day." Do not promise live filtering. |
| Availability at individual site level? | **No** — category only | Kills middleware-side dimension filtering. Also why site *selection* isn't offered, only site *requests*. |
| Quote/inventory hold? | **No hold**; `PATCH` returns a `VerificationCode` that expires | Countdown reads "This price is confirmed for 12:00. Nothing is reserved until payment goes through." Create-failure path apologises and offers reception. Never claim a hold. |
| All rate plans in one call? | **Yes** | "See all rates" expands instantly, no skeleton. |
| Promo validation endpoint? | **Yes** | Inline valid/invalid feedback on the results page. |
| Deposit schedule returned? | **Yes** (`DepositAmount`) | Deposit vs pay-in-full cards, each with its own surcharge line. |
| Integer availability counts per date? | **Unknown** | Built Tier A ("Only 1 left"), degrades to nothing cleanly — the card has no hole without a badge. |
| Reasons for unavailability (min stay, CTA/CTD)? | **Unknown** | Built Tier A from `MinStay`/`MaxStay`/`ClosedToArrival`/`ClosedToDeparture`. If absent, derive in middleware by probing; only the timing changes, not the UI. |

---

## 4. Booking flow

```
Home search bar  ─┐
Type calendar    ─┼─→ cart (localStorage, 10 min)
Results page     ─┘        │
                           ▼
        Checkout: details → options → payment → confirmed
```

- **Cart** — multiple stays in one reservation, as `RoomStays[]`. One deposit, one
  card charge, one reference. Header cart button shows the count and a panel listing
  each stay; "Add another" returns to the home search bar (`Kosipark.dc.html#book`).
- **Details step** — validation lives in one predicate, `detailsError()`. Every
  route forward consults it (step pills, both Continue buttons) so nothing can skip
  it. Fields: name, email, mobile, address, plus profile fields from
  `beprofilefields`, plus rig dimensions for sites / vehicle type for cabins, plus
  T&Cs consent. Lead capture fires here.
- **Options step** — "Extend your stay" quotes the real adjacent-night rates via
  `extendQuotes()`, keyed to the cart's first stay (a quote for a different stay is
  never charged). Then extras with their price basis shown.
- **Payment** — deposit or in full, each showing its own 2% surcharge as a separate
  line before commitment. This was a client requirement after a dispute.
- **Restrictions** — five kinds (min nights, max nights, closed-to-arrival,
  closed-to-departure, closed dates). Each surfaces with a plain-English reason and
  a one-tap fix that changes the selection. Never a dead grey cell.

---

## 5. Known gaps

1. **Photography.** Every image is an empty `<image-slot>` with a caption saying what
   belongs there (~60 of them). Drops persist in `.image-slots.state.json`. This is
   the biggest visual change outstanding.
2. **`Manage.dc.html` runs on mock bookings** (`KTP-48213`/Nguyen,
   `KTP-51907`/Doyle, `KTP-52440`/Reid). The API has **no endpoint to retrieve a
   reservation by reference**, so guest self-service lookup needs your middleware to
   store bookings. Until then the page collects *requests* and says so.
3. **Card tokenisation** is stubbed (`CardNumberToken: "tok_" + last4`). Needs the
   real payment provider before any live payment.
4. **Google Map** — the Contact and home location slots are placeholders for an embed.
5. **Not yet designed**: cash-on-arrival payment option; upgrade-comparison upsell
   ("+$40/night for a cabin with a bathroom"); a dedicated extras step separate from
   options; site-preference map.

---

## 6. Content provenance

Room descriptions, facilities, attractions and terms are taken from
kosipark.com.au (read September 2026) — treat that copy as the client's own voice and
don't rewrite it without asking.

Terms clauses **added** during this work, not on the current site:

- **Site allocation** — "Specific sites may be requested but are not guaranteed…"
- **Equipment details** — declare type and dimensions; material differences may mean
  we can't accommodate, no refund
- **Rate plans** — the selected rate's conditions prevail
- **Incomplete bookings** — lead-capture disclosure, with opt-out

Corrected facts: check-in is **3pm** (not 2pm), pets are **prohibited** throughout
the park (NPWS), 14 km to Perisher, guest rating 4.4.

---

## 7. Visual system

- **Type** — Petrona (serif, headings and figures) + Figtree (sans, UI and body), Google Fonts.
- **Colour** — forest `#1D3730`, deep `#122720`, sage `#74836A`, pale sage `#A9B995`,
  copper accent `#96592A`, sand `#F3ECE0`, paper `#FCFAF6`, cream `#EDE4D4`,
  ink `#292724`, body grey `#56534C`.
  Availability scale: available `#DCEBD6`, booked out `#F6DED7`, restricted `#F7E7E1`,
  selected `#1D3730`.
- **Contrast** — the copper is `#96592A` deliberately: the earlier `#B0703A` measured
  3.86:1 against cream at 12px bold and failed WCAG AA. Don't lighten it behind small text.
- **Styling is inline**, on purpose — no stylesheet to keep in sync. Responsive
  behaviour uses `clamp()` plus JS width flags where a media query isn't available.

---

## 8. Conventions worth keeping

- **Availability signals must be real.** Scarcity comes from API counts, nudges from
  actual rates. No countdown timers on upsells, no invented "15 people viewing".
  Regulators have pursued fake urgency, and the client's voice is plain.
- **Every restriction gets a reason and a fix**, not just a disabled state.
- **Never present an unpriced booking as payable** — the confirm button disables and
  offers reception instead. There are guards at three levels (cart read, calendar
  selection, payment).
- **One source of truth per fact.** Party size, dates and totals are computed once
  (`partyLabel`, `partyTotalLabel`, `dates()`, `totals()`) because duplicating them
  caused real drift between the summary and the confirmation.
