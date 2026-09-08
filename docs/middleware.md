# Kosipark integration control plane

Brand source of truth: `public_html/assets/kosipark-logo-2015.png`, supplied by
Kosciuszko Tourist Park on 8 September 2026. Use this original transparent PNG
for the operations product rather than recreating or approximating the mark.

The middleware lives at `/ops/` with its private JSON API at
`/api/ops/index.php`. It is intentionally separate from the public booking API.
The browser is an operations console, not an integration client: GuestPoint,
TTLock and boom-gate calls are made only by the PHP backend.

## Architecture

```text
GuestPoint change feed ─┐
                       ├─> ingest/deduplicate ─> durable job ─> provider adapter
Boom-gate callbacks ───┘          │                    │             │
                                  └─ event log         ├─ retry      ├─ TTLock
                                     + trace           └─ issue      └─ boom gate
                                         │
                                  operations console
                            (masked metadata + redacted logs)
```

Every logical change needs one stable idempotency key and one correlation ID.
The idempotency key prevents duplicate access credentials; the correlation ID
joins the booking event, mapping decision, provider request, response and retry
into one readable trace.

## What this version does

- Shows a live topology for GuestPoint → middleware → TTLock / boom gate.
- Shows only masked credential suffixes, scope, version and rotation metadata;
  full credentials never reach the browser.
- Imports the TTLock lock list and tests provider connections from the backend.
- Stores a one-to-one mapping between GuestPoint physical `RoomId` values and
  TTLock `lockId` values, plus whether the unit should receive gate access.
- Checks GuestPoint's changed-reservations feed as a dry run.
- Keeps structured events with source, target, status, correlation ID and a
  redacted trace. The latest 500 are retained in the starter file store.
- Groups retryable failures as issues and records operator-requested safe retries.

It does **not** create guest passcodes or operate the boom gate yet. Those writes
remain disabled until real room mappings are reviewed and the boom-gate vendor
confirms its production authentication and which side hosts gate control.

## Backend boundaries

| Boundary | Owns | Never sends to browser |
|---|---|---|
| Secret store | Provider credentials, versions, rotation dates | Full secret values |
| GuestPoint adapter | Change feed, reservation lookup, access-code writeback | Guest PII and raw responses |
| TTLock adapter | Inventory, credential lifecycle, gateway status | Access tokens and passcodes |
| Boom-gate adapter | Visitor lifecycle and entry/exit events | Signing secret and raw personal data |
| Reconciler | Desired vs actual access state, idempotency, retry policy | Unredacted payloads |
| Event store | Redacted trace, status, correlation and issue grouping | Request headers or secrets |

The current JSON state store is deliberately small and dependency-free for
Hostinger. Before processing live reservations, move jobs, idempotency keys and
events to a transactional database so multiple workers cannot claim the same job.

## Server setup

1. Set `OPS_ADMIN_USERNAME`, `OPS_ADMIN_PASSWORD_HASH`, `OPS_ADMIN_EMAIL`,
   `OPS_SESSION_SECRET`, `OPS_APP_URL` and `OPS_MAIL_FROM`. The commands for
   generating both are in `public_html/api/ops/config.sample.php`.
2. Set GuestPoint Core values: `GP_PROPERTY_ID`, `GP_CORE_API_KEY`, and
   `GP_CORE_UPSTREAM`.
3. Set TTLock values: `TTLOCK_CLIENT_ID` and `TTLOCK_ACCESS_TOKEN`.
4. Optionally set `OPS_DATA_DIR` to a writable directory outside `public_html`.
   The default is a sibling named `kosipark-middleware`.
5. Visit `/ops/`, sign in, import locks, then add the room mappings.

For local preview, either open `public_html/ops/index.html` directly or run
`python3 dev-server.py` and visit `/ops/`. Use username `admin` and password
`demo`. Both mock
paths contain sample data only; neither bypasses the deployed PHP middleware.

If environment variables are unavailable, copy `config.sample.php` to
`config.php` and fill the values there. `config.php` is blocked from the web and
must never be committed.

## Authentication and password recovery

The console now requires both a username and password. Hosted passwords are
never stored as plaintext. Recovery creates a cryptographically random,
single-use token, stores only its SHA-256 hash, emails a 30-minute reset link,
and replaces the password with a PHP `password_hash` value in the private data
directory. Responses to reset requests are deliberately identical whether or
not the username exists.

## AI diagnostics

Set `OPS_AI_PROVIDER` to `openai` or `anthropic`, set `OPS_AI_MODEL` explicitly,
and provide the matching server-side API key. Only the selected issue and its
correlation trace are sent. Passcodes, PINs, tokens, keys, contact details and
likely numeric codes are recursively redacted first.

Every result is stored as `proposal_only`. The model has no lock, gate, shell,
GitHub or deployment tool. The intended repair lifecycle is diagnosis → proposed
change → operator approval → constrained job → verification → audit. Physical
unlock, block or gate-open actions should require a dedicated permission and a
second confirmation when those capabilities are added.

## Hosting, deployment and recovery

The PHP console fits the existing Hostinger service. Use Hostinger cron for
reconciliation and queue workers; shared hosting is not an always-on WebSocket
or worker environment. Webhook handlers should acknowledge quickly, persist an
idempotency key and let a worker perform provider writes.

Recovery needs three independent layers:

1. GitHub stores source code, migrations and configuration samples only.
2. Hostinger automated backups cover deployed files and the production database.
3. A separate encrypted off-site destination receives scheduled database/state
   exports, with quarterly restore tests.

Never commit database dumps, reset tokens, guest data, provider credentials,
`.env` or `config.php` to GitHub. Deployment continues through reviewed pull
requests and the repository's `deploy` branch; AI-generated changes must follow
the same review path.

## Next slice

The next implementation should ingest changed reservations into durable jobs,
derive the desired access window (normally 3pm arrival to checkout time), and
reconcile each job against TTLock. Credential creation must be idempotent: a
timeout must be checked before retrying so two valid guest codes cannot be
created for one stay. Only after TTLock confirms success should the access code
be written back to GuestPoint's room-allocation access-code endpoint.
