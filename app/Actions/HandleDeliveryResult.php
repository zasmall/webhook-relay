<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\SentAttempt;
use App\Enums\DeliveryOutcome;
use App\Enums\DeliveryStatus;
use App\Enums\EndpointDisabledReason;
use App\Events\DeliveryDeadLettered;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Support\RetryAfter;
use App\Support\RetrySchedule;

/**
 * Applies the retry policy to an attempt:
 *
 * | Outcome      | Delivery                               | Endpoint                         |
 * | ------------ | -------------------------------------- | -------------------------------- |
 * | 2xx          | succeeded                              | failures reset                   |
 * | 410 Gone     | dead                                   | disabled (gone)                  |
 * | 429          | retry after Retry-After, else backoff  | failures unchanged               |
 * | other        | retry with backoff                     | failures + 1, breaker check      |
 *
 * A retry that would exceed max_attempts dead-letters instead.
 */
final class HandleDeliveryResult
{
    public function __construct(
        private readonly RetrySchedule $retrySchedule,
        private readonly DisableEndpoint $disableEndpoint,
    ) {}

    public function handle(Delivery $delivery, SentAttempt $sent): void
    {
        $attempt = $sent->attempt;
        $endpoint = $delivery->endpoint;

        // Attempts in the current run, which a replay resets; the attempt
        // row's number is the lifetime sequence.
        $delivery->attempts++;
        $delivery->last_status_code = $attempt->status_code;

        match (DeliveryOutcome::fromStatus($attempt->status_code)) {
            DeliveryOutcome::Succeeded => $this->succeed($delivery, $endpoint),
            DeliveryOutcome::Gone => $this->gone($delivery, $endpoint),
            DeliveryOutcome::RateLimited => $this->retryOrDeadLetter($delivery, $this->rateLimitDelay($delivery, $sent)),
            DeliveryOutcome::Failed => $this->fail($delivery, $endpoint),
        };
    }

    private function succeed(Delivery $delivery, Endpoint $endpoint): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Succeeded,
            'delivered_at' => now(),
            'next_attempt_at' => null,
        ])->save();

        Endpoint::whereKey($endpoint->id)->where('consecutive_failures', '>', 0)->update(['consecutive_failures' => 0]);
    }

    private function gone(Delivery $delivery, Endpoint $endpoint): void
    {
        $this->deadLetter($delivery);
        $this->disableEndpoint->handle($endpoint, EndpointDisabledReason::Gone);
    }

    private function fail(Delivery $delivery, Endpoint $endpoint): void
    {
        Endpoint::whereKey($endpoint->id)->increment('consecutive_failures');

        $failures = (int) Endpoint::whereKey($endpoint->id)->value('consecutive_failures');
        $endpoint->consecutive_failures = $failures;
        $endpoint->syncOriginalAttribute('consecutive_failures');

        if ($failures >= config()->integer('relay.circuit_breaker.failure_threshold')) {
            $this->disableEndpoint->handle($endpoint, EndpointDisabledReason::CircuitBreaker);
        }

        // Still scheduled: if the breaker tripped, the retry waits as pending
        // until the endpoint is re-enabled.
        $this->retryOrDeadLetter($delivery, $this->retrySchedule->delayFor(max(1, $delivery->attempts)));
    }

    private function rateLimitDelay(Delivery $delivery, SentAttempt $sent): int
    {
        return RetryAfter::seconds($sent->retryAfter, now(), config()->integer('relay.retry.max_retry_after'))
            ?? $this->retrySchedule->delayFor(max(1, $delivery->attempts));
    }

    private function retryOrDeadLetter(Delivery $delivery, int $delaySeconds): void
    {
        if ($delivery->attempts >= config()->integer('relay.retry.max_attempts')) {
            $this->deadLetter($delivery);

            return;
        }

        $nextAttemptAt = now()->addSeconds($delaySeconds);

        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => $nextAttemptAt,
        ])->save();

        // The row is the source of truth; this job is just the fast path.
        // If it's lost, relay:sweep queues the delivery once it's due.
        DeliverWebhook::dispatch($delivery)->delay($nextAttemptAt);
    }

    private function deadLetter(Delivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Dead,
            'next_attempt_at' => null,
        ])->save();

        DeliveryDeadLettered::dispatch($delivery);
    }
}
