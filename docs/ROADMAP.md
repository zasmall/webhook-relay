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

- [ ] `FanOutEvent` job, idempotent delivery creation, dispatched from ingest after commit
- [ ] `DeliverWebhook` job with signing (one `v1` per active secret) and attempt logging
- [ ] Re-check the resolved IP at delivery time (SSRF / DNS rebinding)
- [ ] Tests with `Http::fake()`: signature correctness, 2xx path, attempt rows

## M4 — Reliability

- [ ] Backoff schedule (pure function, unit tested)
- [ ] 429 / `Retry-After`, 410 handling
- [ ] Dead-lettering + `DeliveryDeadLettered` event
- [ ] Circuit breaker + `EndpointDisabled` event
- [ ] Per-endpoint rate limiting

## M5 — Replay

- [ ] Replay single, selected, and all-dead deliveries for an endpoint

## M6 — Dashboard

- [ ] Endpoint list with health indicators
- [ ] Delivery log with filters (status, endpoint, event type, date)
- [ ] Attempt detail view, replay buttons

## M7 — Receiver verification

- [ ] `VerifyRelaySignature` middleware/package with timestamp tolerance
- [ ] Wire into the transaction-categorizer as a consumer

## M8 — Portfolio polish

- [ ] README: problem, architecture diagram, key decisions and tradeoffs, how to run, "what I'd do next"
- [ ] Demo seeder plus a flaky mock receiver to show retries live
- [ ] Screenshots / short GIF of the dashboard
