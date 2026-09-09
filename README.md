# Kosipark V2

The streamlined Kosciuszko Tourist Park website.

## Booking model

- The homepage search opens GuestPoint's hosted secure booking page with dates and guest counts.
- The separate availability calendar uses GuestPoint read-only availability and price data to help guests choose dates.
- Guest details, payment and the final reservation are completed only in the secure booking system.
- The former full in-house checkout remains preserved separately in V1.

## Local preview

Run `python3 dev-server.py 8772`, then open `http://localhost:8772/`.

The hosted booking URL is configured in `public_html/booking-config.js`. The
local preview temporarily uses the supplied Coachmans Eden address to test the
handoff; replace that single value with Kosipark's address when GuestPoint
supplies it.

The agreed V1/V2 responsibilities and publishing boundary are recorded in
`docs/PROJECT-VERSIONS.md`.
