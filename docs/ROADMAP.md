# Roadmap

## M0 — Scaffold

- [x] Laravel + Vue starter kit (Inertia, TypeScript) on native MySQL and Redis
- [x] Pest, Larastan, Pint configured
- [x] Horizon installed with `fanout` and `deliveries` supervisors
- [x] GitHub Actions workflow: Pint check, PHPStan, tests
- [x] `config/relay.php` with all tunables

## M1 — Sources & ingest

- [x] `sources` table, Sanctum tokens, artisan command to create a source and issue a token
- [x] `POST /api/events` with required `Idempotency-Key`
- [x] Tests: auth, validation, payload size limit, duplicate key returns original, concurrent duplicate race

## M2 — Endpoints

- [x] Endpoint CRUD (API + dashboard), generated secrets, secret rotation with a 24h grace window
- [x] Event type matching (exact, `prefix.*`, and `*`)
- [x] SSRF checks on endpoint URLs; operator API tokens kept separate from source tokens

## M3 — Fan-out & delivery

- [x] `FanOutEvent` job, idempotent delivery creation, dispatched from ingest after commit
- [x] `DeliverWebhook` job with signing (one `v1` per active secret) and attempt logging
- [x] Re-check the resolved IP at delivery time (SSRF / DNS rebinding)
- [x] Tests with `Http::fake()`: signature correctness, 2xx path, attempt rows

## M4 — Reliability

- [x] Backoff schedule (pure function, unit tested)
- [x] 429 / `Retry-After`, 410 handling
- [x] Dead-lettering + `DeliveryDeadLettered` event
- [x] Circuit breaker + `EndpointDisabled` event
- [x] Per-endpoint rate limiting
- [x] Sweep job: reset deliveries stuck in `delivering` (worker killed mid-request) and dispatch due retries
- [x] Unique lock covers delayed retries (`ShouldBeUniqueUntilProcessing`, `$uniqueFor` from config)

## M5 — Replay

- [x] Replay single, selected, and all-dead deliveries for an endpoint (API; dashboard "replay all" on the endpoint page)
- [x] Delivery list and detail API (`GET /api/endpoints/{id}/deliveries`, `GET /api/deliveries/{id}`)

## M6 — Dashboard

- [x] Endpoint list with health indicators
- [x] Delivery log with filters (status, endpoint, event type, date)
- [x] Attempt detail view, replay buttons (single and selected, via the M5 API/action)
- [x] Overview page (24h counts, endpoints needing attention) and 5s polling

## M7 — Receiver verification

- [ ] `VerifyRelaySignature` middleware/package with timestamp tolerance
- [ ] Wire into the transaction-categorizer as a consumer

## M8 — Portfolio polish

- [ ] README: problem, architecture diagram, key decisions and tradeoffs, how to run, "what I'd do next"
- [ ] Demo seeder plus a flaky mock receiver to show retries live
- [ ] Re-check delivery log and stats query plans (`EXPLAIN`) against seeded volume
- [ ] Screenshots / short GIF of the dashboard
