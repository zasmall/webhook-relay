# Architecture

## Purpose

Source apps publish events once. The relay delivers each event to every subscribed endpoint, survives receiver outages, and gives operators visibility and replay.

## Data model

| Table               | Key columns                                                                                                                                                                                             | Notes                                                                                                           |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `sources`           | id (ULID), name                                                                                                                                                                                         | Apps allowed to publish; each authenticates with a Sanctum token                                                |
| `endpoints`         | id (ULID), url, description, secret (encrypted), previous_secret (encrypted), previous_secret_expires_at, event_types (json), is_active, consecutive_failures, disabled_at, disabled_reason, deleted_at | Subscriber URLs. `event_types` holds patterns (see below). Soft-deleted so delivery history keeps its endpoint  |
| `events`            | id (ULID), source_id, type, payload (json), idempotency_key, received_at                                                                                                                                | Unique index on `(source_id, idempotency_key)`                                                                  |
| `deliveries`        | id (ULID), event_id, endpoint_id, status, attempts, next_attempt_at, last_status_code, delivered_at                                                                                                     | One row per event × endpoint. Unique on `(event_id, endpoint_id)`                                               |
| `delivery_attempts` | id, delivery_id, attempt, request_headers (json), status_code, response_body (truncated), error, duration_ms, created_at                                                                                | Append-only: the model throws on update or delete. Unique on `(delivery_id, attempt)`. Never stores the payload |

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
3. **DeliverWebhook** (queue `deliveries`, `ShouldBeUnique` by delivery id, `$tries = 1`)
    - Skip unless the delivery is `pending`. Leave it pending if the endpoint is disabled. Mark it `dead` if the endpoint was deleted.
    - Claim it atomically: `UPDATE … SET status = 'delivering' WHERE id = ? AND status = 'pending'`. Zero rows means another worker has it. This backs up `ShouldBeUnique`, so a delivery is never in flight twice.
    - Resolve the host again and refuse non-public addresses (see SSRF below). Pin the connection to the checked IP with `CURLOPT_RESOLVE`, and never follow redirects.
    - Sign the envelope and POST it with the configured connect and request timeouts.
    - Record a `delivery_attempts` row whatever the outcome, including refused and failed connections.
    - If the job dies unexpectedly, `failed()` returns a stuck `delivering` delivery to `pending`.
    - Handle the result as follows:

| Result             | Action                                                   |
| ------------------ | -------------------------------------------------------- |
| 2xx                | `succeeded`; reset the endpoint's `consecutive_failures` |
| 410 Gone           | `dead`; disable the endpoint                             |
| 429                | Retry after `Retry-After` (capped), else normal backoff  |
| Other / timeout    | Increment failures; retry with backoff                   |
| Attempts exhausted | `dead`; fire `DeliveryDeadLettered`                      |

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

Exponential backoff with full jitter, configured in `config/relay.php`. The default is 8 attempts spanning roughly 6 hours. The job releases itself with a computed delay rather than relying on the worker's `$backoff`, so the schedule is testable as a pure function.

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

When `consecutive_failures` reaches the threshold, the endpoint is auto-disabled and `EndpointDisabled` fires so the owner can be notified. Pending deliveries for a disabled endpoint stay `pending` and are not attempted. Re-enabling the endpoint resumes them.

## Rate limiting

`RateLimited` job middleware, keyed per endpoint on Redis, protects receivers from bursts.

## Replay

Operators can redeliver selected or all `dead` deliveries for an endpoint, from the API and the dashboard. A replay resets `attempts` and keeps the attempt history.

## Receiver verification

A small package/middleware (`VerifyRelaySignature`) does the following:

- Parses `X-Relay-Signature` and accepts the request if any `v1` signature matches (there are two during a secret rotation).
- Rejects requests with timestamps outside a tolerance window, which blocks replays.
- Compares signatures with `hash_equals`.

The transaction-categorizer project consumes it.

## Observability

- Horizon runs separate supervisors for `fanout` and `deliveries`.
- The dashboard shows endpoint health, a filterable delivery log, and attempt detail (request headers, response code and body, timing).

## Non-goals

- Guaranteed ordering
- Exactly-once delivery: delivery is at-least-once, and receivers dedupe by event id
- Payload transformation
- Multi-region
