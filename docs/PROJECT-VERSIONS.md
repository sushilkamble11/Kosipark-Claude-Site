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

V2 must never be published with another property's demonstration booking URL.
Replace the temporary URL in `public_html/booking-config.js` with Kosciuszko
Tourist Park's GuestPoint hosted booking URL, run the checks, then publish V2.

