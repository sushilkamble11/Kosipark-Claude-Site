# Kosipark website versions

This file preserves the agreed product direction so future work does not need
to be explained again.

## Shared source of truth

GuestPoint remains the single source of truth for accommodation categories,
availability, rates and reservations. V1 and V2 are separate website
codebases, not separate booking databases.

## V2 — public website

- Streamlined, responsive marketing website.
- The homepage search sends dates and guest counts to GuestPoint's hosted
  secure booking page.
- The website can use permitted read-only GuestPoint endpoints for availability,
  rates and genuine on-page recommendations.
- Guest details, payment and reservation creation happen on GuestPoint's hosted
  booking page.
- No website cart, guest-details form or payment form.

## V1 — preserved future checkout

- Preserved in `../site`.
- Intended for a future fully integrated checkout if GuestPoint Pay and the
  required payment/reservation permissions become available.
- Must not be replaced or deleted while V2 is the public website.

## Publishing rule

The temporary Hostinger preview may use the supplied Kosipark GuestPoint
development property only while every page carries the test-environment banner.
Before the real launch, install the production Booking Engine API key, property
ID and API base URL in the server-only `api/gp/config.php`; then replace the
hosted booking URL in `public_html/booking-config.js`, set
`bookingEnvironment` to `production`, remove the one-category test limit, run
all checks and publish V2. Never publish another property's booking URL.
