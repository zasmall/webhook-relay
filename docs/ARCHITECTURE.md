# Architecture

## Purpose

Source apps publish events once. The relay delivers each event to every subscribed endpoint, survives receiver outages, and gives operators visibility and replay.

## Data model

| Table               | Key columns                                                                                                                                                                                             | Notes                                                                                                                                                                           |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `sources`           | id (ULID), name                                                                                                                                                                                         | Apps allowed to publish; each authenticates with a Sanctum token                                                                                                                |
| `endpoints`         | id (ULID), url, description, secret (encrypted), previous_secret (encrypted), previous_secret_expires_at, event_types (json), is_active, consecutive_failures, disabled_at, disabled_reason, deleted_at | Subscriber URLs. `event_types` holds patterns (see below). Soft-deleted so delivery history keeps its endpoint                                                                  |
| `events`            | id (ULID), source_id, type, payload (json), idempotency_key, received_at                                                                                                                                | Unique index on `(source_id, idempotency_key)`                                                                                                                                  |
| `deliveries`        | id (ULID), event_id, endpoint_id, status, attempts, next_attempt_at, last_status_code, delivered_at, replay_count, last_replayed_at                                                                     | One row per event × endpoint. Unique on `(event_id, endpoint_id)`. `attempts` counts the current run and is reset by a replay                                                   |
| `delivery_attempts` | id, delivery_id, attempt, request_headers (json), status_code, response_body (truncated), error, duration_ms, created_at                                                                                | Append-only: the model throws on update or delete. `attempt` is a lifetime sequence that continues across replays; unique on `(delivery_id, attempt)`. Never stores the payload |

`DeliveryStatus` enum: `pending`, `delivering`, `succeeded`, `dead`.

## Flow

1. **Ingest:** `POST /api/events` (Sanctum, `Idempotency-Key` header required)
    - Reject bodies over `relay.ingest.max_payload_bytes` with 413, measured on the raw body before parsing.
    - Validate with 422: the key (max 255 chars), the `type` (dot-separated lowercase segments such as `invoice.paid`), and the `payload` (a non-empty JSON object).
    - Insert the event without checking first. On a unique-index violation, load the existing event and return 200 with it. Otherwise return 202.
    - A duplicate key returns the original event even if the retry's type or payload differ. Comparing bodies (Stripe-style 422 on mismatch) was considered and rejected as unnecessary complexity for retries that are almost always identical.
    - Dispatch `FanOutEvent` after commit, for duplicates as well as new events. Fan-out is idempotent, so a duplicate repairs an event whose original dispatch was lost (for example, Redis was briefly down).

    Sources are created with `php artisan relay:source:create {name}`, which prints the source's token once.

    The payload is decoded as objects, not PHP arrays, so `{}` is stored and delivered as `{}` rather than `[]`. MySQL's JSON column does not preserve object key order; receivers must not depend on it.

2. **FanOutEvent** (queue `fanout`)
    - Select active endpoints whose `event_types` match and that existed when the event was received (`created_at <= received_at`). The second condition stops a re-run from sending old events to endpoints added later.
    - Bulk insert deliveries with `insertOrIgnore`, which keeps re-runs safe.
    - Dispatch one `DeliverWebhook` per delivery of the event that is `pending` and due (`next_attempt_at` empty or past).
    - Disabled endpoints get no deliveries for events published while they're off. Replay (M5) covers recovery.
3. **DeliverWebhook** (queue `deliveries`, `ShouldBeUniqueUntilProcessing` by delivery id, `RateLimited` per endpoint)
    - Skip unless the delivery is `pending`. Leave it pending if the endpoint is disabled. Mark it `dead` if the endpoint was deleted.
    - Claim it atomically: `UPDATE … SET status = 'delivering' WHERE id = ? AND status = 'pending'`. Zero rows means another worker has it. This backs up the unique lock, so a delivery is never in flight twice.
    - Resolve the host again and refuse non-public addresses (see SSRF below). Pin the connection to the checked IP with `CURLOPT_RESOLVE`, and never follow redirects.
    - Sign the envelope and POST it with the configured connect and request timeouts.
    - Record a `delivery_attempts` row whatever the outcome, including refused and failed connections.
    - If the job dies unexpectedly, `failed()` returns a stuck `delivering` delivery to `pending`.
    - Apply the retry policy (`HandleDeliveryResult`):

| Result             | Delivery                                                     | Endpoint                                  |
| ------------------ | ------------------------------------------------------------ | ----------------------------------------- |
| 2xx                | `succeeded`                                                  | Reset `consecutive_failures`              |
| 410 Gone           | `dead`; fire `DeliveryDeadLettered`                          | Disable (`gone`); fire `EndpointDisabled` |
| 429                | Retry after `Retry-After` (capped), else backoff             | Unchanged: the receiver is up, just busy  |
| Other / timeout    | Retry with backoff                                           | `consecutive_failures` + 1; breaker check |
| Attempts exhausted | `dead`; fire `DeliveryDeadLettered` (429s count toward this) | —                                         |

## Request format

The body is a JSON envelope. The exact bytes that are signed are the bytes sent:

```json
{
    "id": "01m4cb209hnzq4dwc54g7thzwm",
    "type": "invoice.paid",
    "created_at": "2026-10-07T23:26:55Z",
    "data": { "invoice_id": "inv_42" }
}
```

`id` is the event id. Delivery is at-least-once, so receivers dedupe on it.

Headers:

| Header                | Value                                                |
| --------------------- | ---------------------------------------------------- |
| `Content-Type`        | `application/json`                                   |
| `User-Agent`          | `relay.delivery.user_agent`                          |
| `X-Relay-Event-Id`    | Event id (same as the body's `id`)                   |
| `X-Relay-Event-Type`  | Event type                                           |
| `X-Relay-Delivery-Id` | Delivery id (one per endpoint)                       |
| `X-Relay-Signature`   | `t=<unix>,v1=<hex>`, with one `v1` per active secret |

`v1` is HMAC-SHA256 of `"{t}.{raw_body}"` with the endpoint secret. The scheme version fixes the algorithm, so it isn't configurable.

Response bodies are stored truncated to `relay.delivery.response_body_limit` bytes, cut on a UTF-8 character boundary, with invalid bytes replaced.

## Retry policy

Exponential backoff with full jitter (`RetrySchedule`, a pure function with an injectable randomizer). The delay before the next attempt is random in `[0, min(max_delay, base_delay × 2^(n-1))]`. The defaults are 8 attempts, a 3-minute base and a 3-hour cap, so the worst case is about 6 hours and the average about half that.

**The database is the source of truth for the schedule.** After a failure, the delivery goes back to `pending` with `next_attempt_at` set, and a new `DeliverWebhook` is dispatched with that delay. That job is only the fast path. If it's lost (a Redis flush, a crash), `relay:sweep` queues the delivery once it's due. This replaced an earlier plan for the job to `release()` itself: that would keep the schedule only in Redis, and the sweep was needed anyway for stuck deliveries.

**Uniqueness.** `DeliverWebhook` is `ShouldBeUniqueUntilProcessing`:

- The lock on the delivery id is held while the job waits on the queue, including delayed retries and rate-limit releases.
- The lock is released just before `handle()` runs, so the running job can dispatch its own retry.
- `$uniqueFor` is derived from the longest possible delay, so the lock outlasts any wait.
- The atomic claim guards the request itself.

**Job attempts vs. delivery attempts.** The job has `$tries = 0` and `$maxExceptions = 1`. Rate-limit releases are unlimited and don't count. A real exception or timeout fails the job once, and `failed()` resets the delivery. Delivery attempts are counted on the row, against `relay.retry.max_attempts`.

## Sweep

`php artisan relay:sweep` runs every minute (`withoutOverlapping`). `composer dev` starts `schedule:work`; production needs the usual cron entry. Each run does two things:

1. Resets deliveries stuck in `delivering` for longer than `relay.sweep.stale_after` to `pending`. That happens when a worker is killed mid-request. The request may have reached the receiver, so it may be sent again; delivery is at-least-once.
2. Queues pending deliveries that are due, for active endpoints (deleted ones included, so their deliveries get marked `dead`), oldest first, up to `relay.sweep.batch_size`. Deliveries whose job is still waiting are skipped by the unique lock.

## Endpoints

Operators manage endpoints from the dashboard or from `/api/endpoints` (index, store, show, update, destroy, plus `POST /api/endpoints/{id}/rotate-secret`). Both call the same actions and share one set of validation rules.

**Event-type patterns.** Event types are dot-separated lowercase segments (`invoice.paid`). An endpoint subscribes with patterns:

- an exact type: `invoice.paid`
- everything: `*`
- a prefix: `invoice.*` matches any type under `invoice.` at any depth, but not `invoice` itself

Matching happens in SQL. For a type, `EventTypePattern::candidatesFor()` lists every pattern that would match it (`invoice.paid` gives `*`, `invoice.*`, `invoice.paid`). The `subscribedTo` scope then runs `JSON_OVERLAPS(event_types, <candidates>)`, so fan-out never loads every endpoint into PHP.

**Secrets.** The server generates them (`whsec_` plus 32 random bytes, base64url) and stores them with the `encrypted` cast; callers can't choose one. The API returns the secret only on create and on rotate. The dashboard reveals it behind password confirmation.

**Rotation.** Rotating moves the current secret to `previous_secret`, which expires after `relay.endpoints.secret_rotation_grace` (24 hours). While it's valid, deliveries carry one signature per secret (`t=…,v1=<new>,v1=<old>`), so receivers can switch without rejecting webhooks. Only one previous secret is kept: rotating again inside the window drops the oldest immediately.

**Enable / disable.** Disabling records `disabled_at` and a `disabled_reason` (`manual`, `circuit_breaker`, `gone`). Re-enabling clears those and `consecutive_failures`.

**SSRF protection.** URLs must be `https`, may not embed credentials, and may not point at localhost or at loopback, private, link-local or reserved addresses. Hostnames are resolved, and every resolved address must be public. An unresolvable host is accepted and fails at delivery. Local development relaxes these checks with `RELAY_REQUIRE_HTTPS=false` and `RELAY_ALLOW_PRIVATE_NETWORKS=true`.

This check at save time is not enough on its own: DNS can change after an endpoint is saved (DNS rebinding). So `OutboundAddressGuard` runs on every delivery:

- It resolves the host again and refuses any non-public address, and refuses a host that doesn't resolve.
- It pins cURL to the checked IP, so there is no gap between the check and the connect.
- Redirects are never followed, since one could point at an internal address.

## Authentication

- **Sources** publish events with Sanctum tokens issued by `php artisan relay:source:create {name}`.
- **Operators** (users) use the dashboard with Fortify session auth, and the management API with Sanctum tokens issued by `php artisan relay:user:token {email}`.
- The `token.for:source` and `token.for:user` middleware keep each kind of token on its own routes, so a source token can't manage endpoints and a user token can't publish events.
- Every operator can manage every endpoint. The relay is single-tenant.

## Circuit breaker

`consecutive_failures` counts failed attempts in a row across all of an endpoint's deliveries. 429s don't count, and any 2xx resets it. When it reaches `relay.circuit_breaker.failure_threshold`, the endpoint is disabled with reason `circuit_breaker` and `EndpointDisabled` fires.

Disabling is a conditional `UPDATE … WHERE is_active = true`, so when several workers cross the threshold at once, exactly one disables the endpoint and fires the event.

Pending deliveries for a disabled endpoint stay `pending` and are not attempted. Re-enabling clears the breaker state, and the next sweep (within a minute) queues the backlog, throttled by the rate limiter.

**Notifications.** `DeliveryDeadLettered` and `EndpointDisabled` have listeners that log a warning with ids, status and reason, never the payload. Endpoints have no owner to email; the dashboard shows endpoint health (M6).

## Rate limiting

`RateLimited('deliveries')` job middleware, keyed `endpoint:<id>` at `relay.rate_limit.per_minute`, protects receivers from bursts. The cache store is Redis. A limited job is released back onto the queue, still holding its unique lock, and the release doesn't count as an attempt.

## Replay

Operators can redeliver `dead` deliveries for an endpoint: one, a selection, or all of them (`ReplayDeliveries`).

- **What a replay does.** A conditional bulk update, `WHERE endpoint_id = ? AND status = 'dead' [AND id IN …]`, sets the deliveries back to `pending` with `attempts = 0`, clears `next_attempt_at`, and bumps `replay_count` and `last_replayed_at`. A `DeliverWebhook` is then queued for each.
- **What it leaves alone.** Only dead deliveries change, so ids that aren't dead (or belong to another endpoint) are ignored, and replaying twice is harmless. Succeeded deliveries are deliberately not replayable, which keeps a mistaken "replay all" from re-sending successful webhooks.
- **Retry budget vs. history.** `deliveries.attempts` counts the current run and is what `max_attempts` and the backoff use, so a replay gets a fresh budget. `delivery_attempts.attempt` is a lifetime sequence (highest existing number + 1), so history is kept and numbering continues: …8, then 9.
- **Disabled or deleted endpoints** are refused with 409 (`ReplayNotAllowed`). Otherwise the operator would see success while nothing is sent.
- **What the receiver sees.** Replays are re-signed with a fresh timestamp but keep the event id, so receivers that dedupe on it still can. Sends are rate limited per endpoint, so "replay all" can't flood a receiver, and if dispatch fails, `relay:sweep` picks the deliveries up.

API (operator tokens):

- `GET /api/endpoints/{endpoint}/deliveries?status=dead`: cursor-paginated, newest first.
- `GET /api/deliveries/{delivery}`: the delivery with its attempt log.
- `POST /api/deliveries/{delivery}/replay`: a single delivery.
- `POST /api/endpoints/{endpoint}/replay` with `{"delivery_ids": [...]}` (max 500) or `{"all": true}`, never both. Returns `{"replayed": n}`.

Dashboard: the endpoint page shows the dead count with a **Replay all** button. Single and selected replays live in the delivery log (M6).

## Receiver verification

A small package/middleware (`VerifyRelaySignature`) does the following:

- Parses `X-Relay-Signature` and accepts the request if any `v1` signature matches (there are two during a secret rotation).
- Rejects requests with timestamps outside a tolerance window, which blocks replays.
- Compares signatures with `hash_equals`.

The transaction-categorizer project consumes it.

## Observability

- Horizon runs separate supervisors for `fanout` and `deliveries`.
- Dashboard pages poll every 5 seconds with Inertia's `usePoll`, which pauses while the tab is hidden. Each poll reloads only the props that change.

**Overview** (`/dashboard`): counts over `relay.health.window_hours` (24h):

- events received
- delivered (2xx attempts)
- failed attempts
- dead-lettered deliveries
- current pending backlog

It also has a **Needs attention** list: failing endpoints first, then disabled ones.

**Endpoint health.** `EndpointStatsQuery` computes pending and dead counts, attempts and 2xx attempts in the window, and the last attempt time, for any number of endpoints in two grouped queries. `EndpointHealth::classify()` is pure and unit tested, and turns those stats into one label:

| Health   | When                                                                     |
| -------- | ------------------------------------------------------------------------ |
| Disabled | `is_active` is false                                                     |
| Failing  | consecutive failures ≥ half the breaker threshold, or success rate < 50% |
| Degraded | any consecutive failures, or success rate < 95%                          |
| Healthy  | otherwise, with attempts in the window                                   |
| Idle     | no attempts in the window                                                |

The 95% and 50% cut-offs live in `relay.health`.

**Delivery log** (`/deliveries`), served by `DeliveryLogQuery`:

- Newest first, with cursor pagination. ULIDs sort by creation time, so deep pages cost the same as the first.
- Filters are kept in the query string so a view can be bookmarked: status, endpoint, event type (exact, `prefix.*` or `*`), and an inclusive date range.
- The prefix filter escapes `LIKE` wildcards, because `_` is legal in event types.
- Dead rows can be selected and replayed. A selection may span endpoints: it's grouped by endpoint, and groups whose endpoint is disabled or deleted are skipped and reported (`ReplaySelectedDeliveries`).

**Delivery detail** (`/deliveries/{id}`):

- The event, its endpoint, the next attempt time and its replay history.
- The pretty-printed payload. It's shown only to logged-in operators and is never logged.
- An expandable timeline of every attempt, with status or error, duration, request headers and the truncated response body.
- A Replay button when the delivery is dead.

**Guard rails.** `Model::shouldBeStrict()` is on outside production. N+1 lazy loading, attributes silently dropped by mass assignment, and reads of unselected columns all throw in development and tests. Turning it on immediately exposed a test whose setup had been silently discarded.

**Indexes.** `deliveries.created_at` (date filter) and `delivery_attempts.created_at` (window stats) were added for the dashboard. The log's query plans still need checking against realistic volume; the dev database is too small for `EXPLAIN` to mean anything (see M8).

## Non-goals

- Guaranteed ordering
- Exactly-once delivery: delivery is at-least-once, and receivers dedupe by event id
- Payload transformation
- Multi-region
