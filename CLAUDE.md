# Working on this repo

Read this before changing anything. It is written for whoever is working —
a person, Claude, Codex — and the rules are the same for all of them.

This is the direct booking site for Kosciuszko Tourist Park (Sawpit Creek, NSW).
It takes real money from real guests, so the bar is "correct", not "plausible".

## The five rules

1. **`npm test` must pass before you push.** Six suites. Every one of them
   exists because something broke in front of a real guest. Do not delete or
   weaken a test to make a change pass — if a test is wrong, say so and explain
   why, don't quietly edit it.

2. **The `deploy` branch is fast-forward only.** Hostinger's Git deploy runs
   `git pull`. A rewritten history makes it refuse with "You have divergent
   branches" and the site stops updating until someone fixes it by hand. Never
   force-push `deploy`. Never `git subtree split` it. Use `./deploy.sh`.

3. **The sample data is not the API.** With no GuestPoint key the site serves
   built-in sample data. Several real bugs came from code written against fields
   the sample data invented and the live Booking Engine has never returned
   (`UnitPrice`, `PerPerson`, `Nights`, `MaxStay`). Before trusting a field name,
   check `docs/guestpoint-specs/booking-engine-api-v2.yaml`. If the sample data
   disagrees with the spec, fix the sample data.

4. **Never fail silently.** The bug that cost the most time on this project was
   a second stay disappearing from the cart with no message — the guest saw one
   stay where they expected two and nothing explained it. If the site cannot do
   what the guest asked, it must say what happened and what to do instead.

5. **Availability and urgency must be real.** Scarcity comes from API counts.
   Nudges come from actually quoted rates. No countdown timers on upsells, no
   invented "15 people viewing", no claiming a room is held — nothing is
   reserved until payment goes through, and the checkout says exactly that.
   Regulators have pursued businesses for fake urgency.

## Working alongside another agent

Two agents on one codebase undo each other unless the boundaries are explicit.

- **Nobody pushes to `main` directly.** Work on a branch, open a PR. CI runs
  `npm test` on every PR — that is the referee, not either agent's judgement.
- **Say which files you are touching** in the PR description, so the other side
  can stay out of them.
- **`deploy` is only ever written by `./deploy.sh`,** after a merge to `main`.
- **Commit messages carry the reasoning.** They are how the other agent learns
  why something is the way it is. Explain the failure you fixed, not the diff.

## Layout

```
public_html/
  Kosipark.dc.html        home            SiteNav.dc.html      header, cart, weather
  Accommodation.dc.html   room list       SiteFooter.dc.html   footer
  Room.dc.html            one room        BookingBar.dc.html   the search bar
  Availability.dc.html    search results  TypeCalendar.dc.html per-room calendar
  Checkout.dc.html        booking flow    Calendar.dc.html     all-types calendar
  Manage.dc.html          find a booking  Terms/Gallery/Contact/Attractions
  guestpoint.js           ALL API calls, the cart, the room-type registry
  gp-images.js            fills photo slots, draws fallbacks
  site.js                 fragment links, the sample-data banner
  vendor-map.js           self-hosted React with a CDN fallback
  api/gp/index.php        the PHP proxy that holds the API key
  .htaccess               clean URLs; blocks config.php, .env, .git, *.md
docs/guestpoint-specs/    all four GuestPoint API specs
docs/HANDOVER.md          hosting, deploy, credentials, what's unfinished
```

`.dc.html` files are pages and components from a Claude Design export.
`support.js` renders them. There is no build step and no framework to install —
open a file in an editor and change it. Inline styles are deliberate.

`guestpoint.js` is the only file that talks to the API. Pages import it.
Nothing else fetches.

## Running it

```bash
python3 dev-server.py 8000   # applies the same routes as .htaccess
npm test                     # must be green before you push
```

| Suite | What it protects |
|---|---|
| `test/mapping.test.mjs` | GuestPoint responses → site shapes, against the real spec |
| `test/cart.test.mjs` | the cart keeps every stay; stale prices are flagged, not reused |
| `test/extras.test.mjs` | add-on pricing against `BookingExtraOutput` |
| `test/calendar-counts.mjs` | per-room-type counts; a calendar month is not one long booking |
| `test/smoke.mjs` | every route renders, console is clean, photos resolve |
| `test/flow.test.mjs` | the guest's real path in a browser: pick a stay, add another, both land |

`smoke` and `flow` drive a real browser via Playwright.

## Still unfinished

- **Card tokenisation is stubbed** (`"tok_" + last4`). The site cannot take a
  real payment until a gateway goes in. This is the blocker between here and
  revenue — not features.
- `/manage` runs on sample bookings, though the API supports guest self-service
  via `POST /reservations/manage`.
- No GuestPoint API key yet, so the whole site is on sample data with a banner
  saying so. See `docs/HANDOVER.md` for how to switch it on.
