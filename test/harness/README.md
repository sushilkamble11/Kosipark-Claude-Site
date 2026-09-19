# Fake-GuestPoint proxy harness

`npm run test:proxy` exercises `public_html/api/gp/index.php` over HTTP against a
fake GuestPoint, instead of against the browser mock in `guestpoint.js`.

That distinction is the whole point. The mock is hand-written and has been
kinder than the real API more than once — the extras-and-card flow shipped with
`npm test` fully green while every successful card charge returned a 502 from
the PHP, because the mock never exercised the PHP at all.

## What it starts

| Port | Process |
|---|---|
| 9911 | `fake-guestpoint.php` — Booking Engine v2, Core v1 and the Phoenix WebAPI on one port |
| 9912 | the real site plus `api/gp/index.php`, via `router.php` |

`server.mjs` writes `public_html/api/gp/config.php` pointing at the fake, and
**restores whatever was there before** on exit, including on crash or Ctrl-C. If
a run is killed hard enough to skip that, the real config is at
`config.php.harness-saved`; the next run refuses to start until you put it back.

No credential in `config.harness.php` is real, and the harness never reaches the
internet.

## Failure modes

`state.json` holds the booking; `mode.txt` selects a failure. Both live in a
temp directory, so a run never leaves anything behind.

| Mode | Behaviour |
|---|---|
| *(empty)* | everything succeeds |
| `declined` | ProxyPost answers `IsPaymentProcessed:false` — definitively no money moved |
| `gateway-500` | ProxyPost answers HTTP 500 — definitively no money moved |
| `gateway-timeout` | ProxyPost never answers — **indeterminate**, money may have moved |
| `no-postback` | charge succeeds, Phoenix posts no room-account payment row |
| `slow-postback` | charge succeeds, the payment row appears on a later read |
| `ignore-profiles` | `SaveRoomAllocationEditWithVirtualRooms` drops the `_Profiles` side-car |
| `expiry-object` | `GetHasCcMapExpired` answers `{"HasExpired":false}` rather than a bare `false` |
| `no-card` | the reservation has no stored card |
| `cancel-reject` | the cancellation save answers HTTP 500 |
| `account-reject` | `SaveTransactionItemDetails` answers HTTP 500 |

`HARNESS_SLOW=1` adds the `gateway-timeout` case, which waits out the proxy's
20-second upstream timeout.

## Payload shapes

Responses are modelled on `docs/guestpoint-specs/*.yaml`, not on the mock:
numerics arrive as strings, and `PaymentRequired` is `"0"` with the balance in
`PayLater`. The Phoenix WebAPI has no spec in this repo, so those shapes come
from observed behaviour and are the harness's weakest assumption — see the
"undocumented assumptions" section of the review doc before trusting a green run
on anything Phoenix-side.

## Known-broken list

`test/proxy.test.mjs` keeps a `KNOWN_BROKEN` set of test ids that are expected
to fail. Fixing a finding means removing its id in the same commit. A test in
that set which starts passing is reported as an error, so the list cannot rot.
