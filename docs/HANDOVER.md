# Kosipark direct booking site — handover

Everything here is yours and runs without Anthropic, OpenAI, or any AI tool.
Hand this file to whoever or whatever picks the work up next.

## What it is

A direct booking site for Kosciuszko Tourist Park, built from a Claude Design
export. Plain HTML, CSS and JavaScript — no build step, no framework to install.
The `.dc.html` files are pages and components; `support.js` is the small runtime
that renders them. You can open any file in a text editor and change it.

- **Live:** https://honeydew-hedgehog-896059.hostingersite.com
- **Repo:** https://github.com/sushilkamble11/Kosipark-Claude-Site
- **Host:** Hostinger, site `honeydew-hedgehog-896059.hostingersite.com`,
  username `u225874642`, document root
  `/home/u225874642/domains/honeydew-hedgehog-896059.hostingersite.com/public_html`

## How a change gets live

Hostinger's Git deploy runs `git pull` on a branch called `deploy`. Two things
follow from that, and both have bitten this project:

1. **The `deploy` branch's tree must BE `public_html`**, not contain it. The
   repo root has `public_html/`; the deploy branch's root is its contents.
2. **`deploy` must only ever fast-forward.** Hostinger's `git pull` refuses a
   rewritten history ("You have divergent branches" in their build log). Never
   force-push it, never `subtree split` it.

`deploy.sh` does it correctly, with plumbing:

```bash
git push origin HEAD:main                 # source of truth
git fetch origin deploy
TREE=$(git rev-parse HEAD:public_html)    # the tree, not a commit
PARENT=$(git rev-parse FETCH_HEAD)
NEW=$(git commit-tree "$TREE" -p "$PARENT" -m "your message")
git push origin "$NEW:refs/heads/deploy"  # append-only
```

A GitHub webhook then pokes Hostinger and it pulls within a minute or so.
If a change doesn't appear: hard-refresh first (browser cache), then check the
file size on the server against your local copy — that tells you in one step
whether it's a deploy problem or a code problem.

## Running it locally

```bash
python3 dev-server.py 8000     # applies the same routes as .htaccess
npm test                       # everything below must pass before deploying
```

`npm test` runs six suites:

| File | What it protects |
|---|---|
| `test/mapping.test.mjs` | GuestPoint response → site shapes, against the real spec |
| `test/cart.test.mjs` | the cart keeps every stay; prices are flagged stale, not silently reused |
| `test/extras.test.mjs` | add-on pricing against `BookingExtraOutput` |
| `test/calendar-counts.mjs` | per-room-type counts; a calendar month is not treated as one long booking |
| `test/smoke.mjs` | every route renders, console is clean, photos resolve |
| `test/flow.test.mjs` | the guest's real path in a browser: pick a stay, add another, both land |

Each of those exists because something broke in front of a real person. Don't
delete one to make a change pass.

## The GuestPoint integration

`public_html/guestpoint.js` is the **only** file that talks to the API. Pages
import it; nothing else fetches. Specs for all four GuestPoint APIs are in
`docs/guestpoint-specs/`.

Requests go to `/api/gp/...`, a small PHP proxy at
`public_html/api/gp/index.php` that holds the API key server-side, allow-lists
endpoints, caches on disk and rate-limits per IP. The browser never sees the key.

**The site is currently on sample data.** There is no API key yet. With no key
the proxy answers a `200` carrying `{"Unconfigured": true}`, `guestpoint.js`
falls back to built-in sample data, and a banner across the bottom of every page
says so. That is why prices, availability and photos are invented right now.

### To go live

1. In hPanel, set environment variables `GP_API_KEY` and `GP_PROPERTY_ID`
   (preferred — the key never lands on disk), or fill in
   `public_html/api/gp/config.php`, which is gitignored and blocked by two
   separate `.htaccess` rules. **Never commit it.**
2. Reload the site. The banner disappears on its own once real data arrives.

### Known gaps before you can take money

- **Card tokenisation is stubbed.** `"tok_" + last4` is a placeholder. A real
  payment gateway has to go in before the site can charge anyone. This is a hard
  blocker, not a polish item.
- `/manage` is built on the self-service API and uses booking reference,
  surname and mobile. Live access still needs `GP_CORE_UPSTREAM` and, if the
  Booking Engine key is not accepted by Core, `GP_CORE_API_KEY`.
- `MaxStay` does not exist in the Booking Engine API. The sample data emits it
  and `Availability.dc.html` reads it, so that message will never fire live —
  either remove it or derive it in the proxy.

## Two security items outstanding

- **The GitHub repo is public and contains GuestPoint's four API specs**, which
  were given to you as their customer. Make the repo private.
- **A Hostinger API token was pasted into a chat.** Revoke it at
  hpanel.hostinger.com/api and issue a new one if you need it.

## House rules the code follows

Worth keeping, because they are why the site is trustworthy:

- **Availability signals must be real.** Scarcity comes from API counts, nudges
  from actual quoted rates. No countdown timers on upsells, no invented "15
  people viewing". Regulators have pursued businesses for fake urgency.
- **Never present an unpriced booking as payable**, and never claim a hold —
  nothing is reserved until payment goes through, and the checkout says so.
- **Never fail silently.** The bug that cost the most time was a second stay
  vanishing from the cart with no message. If the site can't do what the guest
  asked, it must say what happened and what to do instead.
- One name per room type, and it comes from GuestPoint — not a hardcoded table
  in a page. Two such tables drifted and disagreed on screen.

## Where things live

```
public_html/
  Kosipark.dc.html        home            SiteNav.dc.html    header, cart, weather
  Accommodation.dc.html   room list       SiteFooter.dc.html footer
  Room.dc.html            one room        BookingBar.dc.html the search bar
  Availability.dc.html    search results  TypeCalendar.dc.html per-room calendar
  Checkout.dc.html        booking flow    Calendar.dc.html   all-types calendar
  Manage.dc.html          find a booking  Terms/Gallery/Contact/Attractions
  guestpoint.js           ALL API calls, cart, room-type registry
  gp-images.js            fills photo slots, draws fallbacks
  site.js                 fragment links, sample-data banner
  vendor-map.js           self-hosted React with a CDN fallback
  api/gp/index.php        the proxy that holds the API key
  .htaccess               clean URLs; blocks config.php, .env, .git, *.md
docs/guestpoint-specs/    all four GuestPoint API specs
```

## If you hand this to another AI tool

Give it this file and `docs/booking-flow-reference.md`. The two things it most
needs to be told, because both have caused real damage here:

1. **Deploy is fast-forward only.** A force-push to `deploy` breaks the site and
   Hostinger will not recover on its own.
2. **The sample data is not the API.** Several bugs came from code written
   against invented fields (`UnitPrice`, `PerPerson`, `Nights`, `MaxStay`) that
   the real Booking Engine has never returned. Check
   `docs/guestpoint-specs/booking-engine-api-v2.yaml` before trusting a field
   name, and make the sample data match the spec rather than the other way round.
