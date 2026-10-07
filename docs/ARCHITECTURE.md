# Architecture

## Purpose

Source apps publish events once. The relay delivers each event to every subscribed endpoint, survives receiver outages, and gives operators visibility and replay.

## Data model

| Table               | Key columns                                                                                                    | Notes                                                             |
| ------------------- | -------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| `sources`           | id (ULID), name                                                                                                | Apps allowed to publish; each authenticates with a Sanctum token  |
| `endpoints`         | id, url, secret (encrypted), event_types (json), is_active, consecutive_failures, disabled_at, disabled_reason | Subscriber URLs. `event_types` supports exact names and `*`       |
| `events`            | id (ULID), source_id, type, payload (json), idempotency_key, received_at                                       | Unique index on `(source_id, idempotency_key)`                    |
| `deliveries`        | id (ULID), event_id, endpoint_id, status, attempts, next_attempt_at, last_status_code, delivered_at            | One row per event × endpoint. Unique on `(event_id, endpoint_id)` |
| `delivery_attempts` | id, delivery_id, attempt, status_code, response_body (truncated), error, duration_ms, created_at               | Append-only log                                                   |

`DeliveryStatus` enum: `pending`, `delivering`, `succeeded`, `dead`.

## Flow

1. **Ingest:** `POST /api/events` (Sanctum, `Idempotency-Key` header required)
    - Reject bodies over `relay.ingest.max_payload_bytes` with 413, measured on the raw body before parsing.
    - Validate with 422: the key (max 255 chars), the `type` (dot-separated lowercase segments such as `invoice.paid`), and the `payload` (a non-empty JSON object).
    - Insert the event without checking first. On a unique-index violation, load the existing event and return 200 with it. Otherwise return 202.
    - A duplicate key returns the original event even if the retry's type or payload differ. Comparing bodies (Stripe-style 422 on mismatch) was considered and rejected as unnecessary complexity for retries that are almost always identical.
    - Dispatch `FanOutEvent` after commit.

    Sources are created with `php artisan relay:source:create {name}`, which prints the source's token once.

    The payload is decoded as objects, not PHP arrays, so `{}` is stored and delivered as `{}` rather than `[]`. MySQL's JSON column does not preserve object key order; receivers must not depend on it.

2. **FanOutEvent** (queue `fanout`)
    - Select active endpoints whose `event_types` match.
    - Bulk insert deliveries with `insertOrIgnore`, which keeps re-runs safe.
    - Dispatch one `DeliverWebhook` per delivery.
3. **DeliverWebhook** (queue `deliveries`, `ShouldBeUnique` by delivery id)
    - Mark the delivery `delivering`, sign the payload, and POST with a short timeout.
    - Record a `delivery_attempts` row whatever the outcome.
    - Handle the result as follows:

| Result             | Action                                                   |
| ------------------ | -------------------------------------------------------- |
| 2xx                | `succeeded`; reset the endpoint's `consecutive_failures` |
| 410 Gone           | `dead`; disable the endpoint                             |
| 429                | Retry after `Retry-After` (capped), else normal backoff  |
| Other / timeout    | Increment failures; retry with backoff                   |
| Attempts exhausted | `dead`; fire `DeliveryDeadLettered`                      |

## Retry policy

Exponential backoff with full jitter, configured in `config/relay.php`. The default is 8 attempts spanning roughly 6 hours. The job releases itself with a computed delay rather than relying on the worker's `$backoff`, so the schedule is testable as a pure function.

## Circuit breaker

When `consecutive_failures` reaches the threshold, the endpoint is auto-disabled and `EndpointDisabled` fires so the owner can be notified. Pending deliveries for a disabled endpoint stay `pending` and are not attempted. Re-enabling the endpoint resumes them.

## Rate limiting

`RateLimited` job middleware, keyed per endpoint on Redis, protects receivers from bursts.

## Replay

Operators can redeliver selected or all `dead` deliveries for an endpoint, from the API and the dashboard. A replay resets `attempts` and keeps the attempt history.

## Receiver verification

A small package/middleware (`VerifyRelaySignature`) does the following:

- Parses `X-Relay-Signature`.
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
