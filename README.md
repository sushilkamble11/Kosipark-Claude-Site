# Kosciuszko Tourist Park — booking site

Direct booking site for Kosciuszko Tourist Park, Sawpit Creek, Jindabyne NSW.
Plain HTML/CSS/JS with a small PHP proxy. No build step: what is in `public_html`
is what gets served.

`docs/HANDOFF.md` is the original design handoff — the visual system, the copy
decisions and the reasoning behind the booking UX. Read it before changing how
the flow behaves. This file covers running and deploying it.

---

## Run it locally

```bash
python3 dev-server.py          # http://localhost:8000
```

`dev-server.py` applies the same routes as `public_html/.htaccess`, so what you
click locally is what Hostinger will serve. On localhost the GuestPoint client
switches itself into **mock mode** — realistic fixtures, no credentials needed,
no outbound calls — so the whole site is clickable straight away.

To exercise live data locally, open the console and run `__gp.setMock(false)`.
That needs the PHP proxy, which `dev-server.py` does not run.

## Test it

```bash
node test/mapping.test.mjs     # GuestPoint response mapping, live shape + mock
node test/smoke.mjs            # every route in a real browser
node test/smoke.mjs --shots    # ...and write screenshots to test/shots/
```

`smoke.mjs` walks all thirteen URLs, checks the shared components mount, fails
on any console error or failed request, and verifies the photo pipeline fills
the slots it should and leaves the rest as placeholders.

---

## Layout

```
public_html/            everything served at the domain root
  .htaccess             routes, security headers, caching
  *.dc.html             pages and shared components
  support.js            the component runtime — do not edit
  image-slot.js         photo placeholders — do not edit
  guestpoint.js         the only file that talks to the API
  gp-images.js          fills photo slots from GuestPoint
  site.js               fragment-link handling under <base href="/">
  vendor-map.js         points the runtime at the self-hosted React
  vendor/               React + ReactDOM 18.3.1, byte-identical to unpkg's
  api/gp/               the PHP proxy that holds the API key
docs/                   design handoff + all four GuestPoint API specs
test/                   mapping tests and the browser smoke test
dev-server.py           local preview with the production routes
```

### URLs

| URL | Page |
|---|---|
| `/` | Home |
| `/accommodation` | All eight types |
| `/accommodation/<slug>` | One type in detail |
| `/book` | Search results |
| `/book/calendar` | Availability + rates for one type |
| `/book/checkout` | Details → options → payment |
| `/manage` | Booking lookup and changes |
| `/terms`, `/attractions`, `/gallery`, `/contact` | Content |

The old design-canvas filenames (`Kosipark.dc.html` and friends) 301 to these,
so nothing that was ever linked breaks.

---

## Deploying to Hostinger

**Hostinger's API cannot deploy files.** It covers VPS, domains, DNS, email,
billing and WordPress — there is no file-upload endpoint for shared hosting. The
supported route is Git, which is better anyway: every deploy is a commit, and a
bad one is one `git revert` away.

**One-time setup**

1. Push this repo to GitHub.
2. hPanel → **Advanced → Git** → **Connect with GitHub**, install the Hostinger
   GitHub app, pick this repo and the `main` branch.
3. Set the deploy directory to **`public_html`**, and the repository subfolder to
   **`public_html`** as well, so the repo's `public_html/` lands at the site root
   and `docs/`, `test/` and `dev-server.py` never reach the web.
4. Deploy once by hand to confirm, then leave auto-deploy on: every push to
   `main` deploys itself.

**Every deploy after that**

```bash
git add -A && git commit -m "..." && git push
```

**Check after the first deploy**

- `https://kosipark.com.au/` loads and the nav appears (proves `.htaccess` and
  the component loader work).
- `https://kosipark.com.au/accommodation/cedar-cabin` shows the Cedar Cabin, not
  a fallback type.
- `https://kosipark.com.au/config.php` and `/api/gp/config.php` both 403.
- Uncomment the HTTPS and canonical-host redirects at the top of
  `public_html/.htaccess` once DNS points at Hostinger.

---

## Going live on GuestPoint

Nothing in the repo needs editing. Two values go on the server, once:

```bash
cd public_html/api/gp
cp config.sample.php config.php
# set api_key and property_id
```

Better still, leave `config.php` alone and set `GP_API_KEY` and `GP_PROPERTY_ID`
as environment variables in hPanel → Advanced → PHP Configuration. Then the key
is not on the filesystem at all.

`config.php` is gitignored and blocked by two separate `.htaccess` rules. It must
never be committed.

**How the pieces fit**

- The browser calls `/api/gp/properties/self/...`. It never sees the key or the
  property id.
- `api/gp/index.php` adds `X-API-KEY`, substitutes the real property id, and
  forwards to `https://beapi.guestpoint.dev/api/v1`. It allows only the endpoints
  the site uses, caches GETs on disk with the same TTLs the browser uses, and rate
  limits booking attempts per IP.
- `guestpoint.js` switches to live automatically off localhost. There is no flag
  to remember to flip.
- Room types resolve themselves: the eight slugs are matched to GuestPoint's ids
  by name on the first response. `__gp.map()` in the console shows what it found.
  Only fill `id` in `ROOM_TYPES` if two types ever share a name.

**Then check**, on the live site with the console open:

```js
await __gp.content()   // property description, rating, coordinates, photos
__gp.map()             // { "cedar-cabin": "<guid>", ... } — all eight present?
```

---

## Photos

GuestPoint returns them with the availability payload — `PropertyImages` for the
park, `RoomImages` per type, each with a URL, captions by language, and a sort
sequence. `gp-images.js` fills the design's slots from those and uses the caption
as alt text.

A slot says which photo it wants in one of three ways:

- `gp-room="cedar-cabin" gp-index="0"` — nth photo of that room type
- `gp-property gp-index="3"` — nth photo of the park
- `id="r-cedar-cabin-1"` / `id="av-cedar-cabin-2"` — by convention, 1-based

A slot with none of those is never touched, and a slot whose photo GuestPoint
does not have keeps its captioned placeholder. So the site degrades to
placeholders, never to holes. The attractions photos and the two map slots are
deliberately unwired — GuestPoint has nothing to say about them.

Whatever GuestPoint holds is what the site shows, so photos are managed in
GuestPoint, not here.

---

## Still open

1. **Card tokenisation is stubbed** (`CardNumberToken: "tok_" + last4`). A real
   payment provider is required before any live payment. This is the one hard
   blocker to taking money.
2. **`/manage` still runs on mock bookings.** It no longer has to — see below.
3. **Google Maps embed** — the Contact and home map slots are placeholders.
4. **The weather pill** calls `api.open-meteo.com` from each visitor's browser.
   It fails soft, but it is a third-party request on every page load.
5. Not yet designed: cash on arrival, the upgrade-comparison upsell, a dedicated
   extras step, a site-preference map.

### Two handoff gaps that the full API specs close

`docs/HANDOFF.md` was written against a partial spec and marks these unknown or
impossible. All four GuestPoint specs are now in `docs/guestpoint-specs/`, and
they say otherwise:

- **Guest self-service lookup is supported.** `POST /reservations/manage` takes a
  confirmation number plus the lead guest's email and surname and returns the
  booking, with a `Login` object saying which actions the property permits.
  `PATCH /reservations/manage/{confNum}` modifies; `DELETE /reservations/{id}`
  cancels. All three are wired in `guestpoint.js` (`lookupReservation`,
  `modifyReservation`, `cancelReservation`) and allowed through the proxy.
  `Manage.dc.html` still needs rebuilding on top of them — no middleware required.
- **Scarcity and restrictions are both real.** `Availabilities[].ForSale` is an
  integer per night, so "Only 1 left" is honest. `ClosedToArrival`,
  `ClosedToDeparture`, `Closed` and `MinStayArrival` are per-date on the rate, so
  every restriction gets a real reason. Both are already mapped.

One correction the other way: **`MaxStay` is not in the Booking Engine API.** The
mock emits it and the results page reads it, so the max-nights message will never
fire against live data. Either drop it or derive it in middleware.
