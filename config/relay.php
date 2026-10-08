<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | Shows the demo login on the landing page. Seed the demo with
    | `php artisan relay:demo`. Never enable in a real deployment.
    |
    */

    'demo' => (bool) env('RELAY_DEMO', false),

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    |
    | Limits applied to POST /api/events before an event is stored.
    |
    */

    'ingest' => [
        // Maximum raw request body size, in bytes.
        'max_payload_bytes' => (int) env('RELAY_MAX_PAYLOAD_BYTES', 256 * 1024),

        // Maximum length of the Idempotency-Key header.
        'max_idempotency_key_length' => 255,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | Subscriber URL rules and secret rotation. The URL checks guard against
    | SSRF; relax them locally so a receiver on localhost can be used.
    |
    */

    'endpoints' => [
        'require_https' => (bool) env('RELAY_REQUIRE_HTTPS', true),
        'allow_private_networks' => (bool) env('RELAY_ALLOW_PRIVATE_NETWORKS', false),

        'max_event_types' => 50,

        // After a rotation, the previous secret keeps signing deliveries
        // (alongside the new one) for this many seconds.
        'secret_rotation_grace' => 24 * 60 * 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Outbound HTTP behaviour for DeliverWebhook. Timeouts are in seconds.
    |
    */

    'delivery' => [
        'connect_timeout' => (int) env('RELAY_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('RELAY_TIMEOUT', 10),

        // Receiver response bodies are truncated to this many bytes before
        // being stored on delivery_attempts.
        'response_body_limit' => 2048,

        'user_agent' => 'WebhookRelay/1.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Exponential backoff with full jitter: the delay before retry n is a random
    | value between 0 and min(cap, base * 2^(n-1)). With these defaults, 8
    | attempts span at most ~6 hours (about half that on average).
    |
    */

    'retry' => [
        'max_attempts' => (int) env('RELAY_MAX_ATTEMPTS', 8),
        'base_delay' => 180,
        'max_delay' => 3 * 60 * 60,

        // Upper bound for a receiver's Retry-After header on a 429.
        'max_retry_after' => 60 * 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sweep
    |--------------------------------------------------------------------------
    |
    | relay:sweep runs every minute. It resets deliveries stuck in
    | "delivering" (a worker died mid-request) and queues pending deliveries
    | that are due, in case their job was lost.
    |
    */

    'sweep' => [
        // Seconds in "delivering" before a delivery is considered stuck. Must
        // exceed the job timeout (connect + request timeout + 5).
        'stale_after' => 120,

        // Most deliveries queued per run, so a big backlog drains gradually.
        'batch_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | An endpoint is auto-disabled once this many deliveries in a row have
    | failed. Pending deliveries wait until the endpoint is re-enabled.
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('RELAY_BREAKER_THRESHOLD', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Dashboard health labels, computed from the endpoint's attempts in the
    | window and its consecutive failures. "Failing" also applies once
    | consecutive failures reach half the circuit breaker threshold.
    |
    */

    'health' => [
        'window_hours' => 24,
        'degraded_below' => 0.95,
        'failing_below' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Maximum deliveries per endpoint per minute, enforced by the RateLimited
    | job middleware.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('RELAY_RATE_LIMIT_PER_MINUTE', 60),
    ],

];
