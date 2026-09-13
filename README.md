# Kosipark V2

The streamlined Kosciuszko Tourist Park website.

## Booking model

- The homepage search opens GuestPoint's hosted secure booking page with dates and guest counts.
- The separate availability calendar uses GuestPoint read-only availability and price data to help guests choose dates.
- Guest details, payment and the final reservation are completed only in the secure booking system.
- The former full in-house checkout remains preserved separately in V1.

## Local preview

Run `python3 dev-server.py 8772`, then open `http://localhost:8772/`.

Without GuestPoint credentials the preview shows clearly labelled sample data.
To test real availability locally, provide `GP_API_KEY` and `GP_PROPERTY_ID` as
process environment variables when starting the preview server. They are read
only by the local server and are never sent to the browser or committed.

For a repeatable restriction test without calling the API, set `GP_HAR_FILE`
to a GuestPoint HAR export. The local server reads only the last successful
package, package-day and inventory responses and labels the calendar
"GuestPoint HAR test data". The HAR is never copied into the project.

The hosted booking URL and environment marker are configured in
`public_html/booking-config.js`. The current values use Kosipark's GuestPoint
development booking site and deliberately show a test-environment banner.

## Production switch

There is one GuestPoint database, not a V1 database and a V2 database. To move
from the tested development connection to live GuestPoint:

1. On the server, replace `GP_API_KEY`, `GP_PROPERTY_ID`, and the Booking Engine
   API base in `public_html/api/gp/config.php` (or the matching PHP environment
   variables). Never place the key in browser JavaScript or commit it.
2. In `public_html/booking-config.js`, replace `secureBookingUrl` with the live
   hosted booking root, change `bookingEnvironment` to `production`, and set
   `testCategoryLimit` to `0`.
3. Run the static and browser checks, verify one real category against the
   GuestPoint dashboard, then publish.

V2 uses only read operations: live categories and images, nightly rates,
quantities, arrival/departure closures, minimum stays, policies, and eligible
extras. It never collects guest details or calls reservation/payment methods.
GuestPoint's hosted page completes the booking and payment.

The agreed V1/V2 responsibilities and publishing boundary are recorded in
`docs/PROJECT-VERSIONS.md`.
