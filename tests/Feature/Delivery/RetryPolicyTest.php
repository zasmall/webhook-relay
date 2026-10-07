<?php

declare(strict_types=1);

use App\Actions\DisableEndpoint;
use App\Enums\DeliveryStatus;
use App\Enums\EndpointDisabledReason;
use App\Events\DeliveryDeadLettered;
use App\Events\EndpointDisabled;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Support\RetrySchedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Random\Engine\Mt19937;
use Random\Randomizer;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Event::fake([DeliveryDeadLettered::class, EndpointDisabled::class]);
    $this->freezeSecond();

    $this->endpoint = Endpoint::factory()->create();
    $this->delivery = Delivery::factory()->create(['endpoint_id' => $this->endpoint->id]);
});

/**
 * Runs the job handler for a delivery that has already made $attemptsMade
 * attempts.
 */
function attemptDelivery(Delivery $delivery, int $attemptsMade = 0): void
{
    for ($n = 1; $n <= $attemptsMade; $n++) {
        DeliveryAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt' => $n,
            'request_headers' => [],
            'status_code' => 500,
            'duration_ms' => 1,
        ]);
    }

    $delivery->forceFill(['attempts' => $attemptsMade])->save();

    app()->call([new DeliverWebhook($delivery), 'handle']);
}

it('retries a failure after a backoff delay', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    attemptDelivery($this->delivery);

    $delivery = $this->delivery->fresh();
    $delay = (int) now()->diffInSeconds($delivery->next_attempt_at);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delay)->toBeBetween(0, app(RetrySchedule::class)->ceiling(1));

    Queue::assertPushedOn('deliveries', DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $delivery->id
        && $job->delay->equalTo($delivery->next_attempt_at));
    Event::assertNotDispatched(DeliveryDeadLettered::class);
});

it('uses a seeded schedule for the delay', function () {
    app()->instance(RetrySchedule::class, new RetrySchedule(100, 1000, new Randomizer(new Mt19937(7))));
    $expected = (new RetrySchedule(100, 1000, new Randomizer(new Mt19937(7))))->delayFor(1);
    Http::fake(['*' => Http::response('boom', 500)]);

    attemptDelivery($this->delivery);

    expect($this->delivery->fresh()->next_attempt_at->equalTo(now()->addSeconds($expected)))->toBeTrue();
});

it('dead-letters when attempts run out', function () {
    config(['relay.retry.max_attempts' => 3]);
    Http::fake(['*' => Http::response('boom', 503)]);

    attemptDelivery($this->delivery, attemptsMade: 2);

    expect($this->delivery->fresh())
        ->status->toBe(DeliveryStatus::Dead)
        ->attempts->toBe(3)
        ->next_attempt_at->toBeNull();

    Queue::assertNotPushed(DeliverWebhook::class);
    Event::assertDispatched(DeliveryDeadLettered::class, fn ($event) => $event->delivery->is($this->delivery));
});

it('dead-letters on 410 Gone and disables the endpoint', function () {
    Http::fake(['*' => Http::response('', 410)]);

    attemptDelivery($this->delivery);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Dead)
        ->and($this->endpoint->fresh())
        ->is_active->toBeFalse()
        ->disabled_reason->toBe(EndpointDisabledReason::Gone);

    Queue::assertNotPushed(DeliverWebhook::class);
    Event::assertDispatched(DeliveryDeadLettered::class);
    Event::assertDispatched(EndpointDisabled::class, fn ($event) => $event->reason === EndpointDisabledReason::Gone);
});

it('waits for Retry-After on 429 without counting a failure', function (string $retryAfter, int $expectedDelay) {
    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => $retryAfter])]);

    attemptDelivery($this->delivery);

    expect($this->delivery->fresh()->next_attempt_at->equalTo(now()->addSeconds($expectedDelay)))->toBeTrue()
        ->and($this->endpoint->fresh()->consecutive_failures)->toBe(0);
})->with([
    'seconds' => ['120', 120],
    'http date' => [fn () => now()->addMinutes(5)->toRfc7231String(), 300],
    'capped' => ['999999', 3600],
]);

it('falls back to backoff on 429 without Retry-After', function () {
    Http::fake(['*' => Http::response('slow down', 429)]);

    attemptDelivery($this->delivery);

    $delay = (int) now()->diffInSeconds($this->delivery->fresh()->next_attempt_at);

    expect($delay)->toBeBetween(0, app(RetrySchedule::class)->ceiling(1));
});

it('still counts 429s toward the attempt limit', function () {
    config(['relay.retry.max_attempts' => 2]);
    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '10'])]);

    attemptDelivery($this->delivery, attemptsMade: 1);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Dead);
});

it('trips the circuit breaker at the failure threshold', function () {
    config(['relay.circuit_breaker.failure_threshold' => 3]);
    $this->endpoint->forceFill(['consecutive_failures' => 2])->save();
    Http::fake(['*' => Http::response('boom', 500)]);

    attemptDelivery($this->delivery);

    expect($this->endpoint->fresh())
        ->is_active->toBeFalse()
        ->consecutive_failures->toBe(3)
        ->disabled_reason->toBe(EndpointDisabledReason::CircuitBreaker)
        // The delivery keeps its retry and waits for re-enable.
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Pending);

    Event::assertDispatched(EndpointDisabled::class, 1);
});

it('does not trip the breaker below the threshold', function () {
    config(['relay.circuit_breaker.failure_threshold' => 3]);
    $this->endpoint->forceFill(['consecutive_failures' => 1])->save();
    Http::fake(['*' => Http::response('boom', 500)]);

    attemptDelivery($this->delivery);

    expect($this->endpoint->fresh()->is_active)->toBeTrue();
    Event::assertNotDispatched(EndpointDisabled::class);
});

it('disables an endpoint and fires the event only once', function () {
    $action = app(DisableEndpoint::class);
    $stale = Endpoint::find($this->endpoint->id);

    expect($action->handle($this->endpoint, EndpointDisabledReason::CircuitBreaker))->toBeTrue()
        ->and($action->handle($stale, EndpointDisabledReason::CircuitBreaker))->toBeFalse();

    Event::assertDispatched(EndpointDisabled::class, 1);
});

it('retries until it succeeds', function () {
    Http::fakeSequence()->push('boom', 500)->push('ok', 200);

    attemptDelivery($this->delivery);

    // Run the delayed retry the first attempt queued, once it's due.
    $retry = Queue::pushed(DeliverWebhook::class)->sole();
    $this->travelTo($retry->delay);
    app()->call([$retry, 'handle']);

    expect($this->delivery->fresh())
        ->status->toBe(DeliveryStatus::Succeeded)
        ->attempts->toBe(2)
        ->next_attempt_at->toBeNull()
        ->and($this->endpoint->fresh()->consecutive_failures)->toBe(0);
});

it('logs dead letters and disabled endpoints without payloads', function () {
    Event::fakeExcept([DeliveryDeadLettered::class, EndpointDisabled::class]);
    Log::spy();
    Http::fake(['*' => Http::response('', 410)]);

    attemptDelivery($this->delivery);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Webhook delivery dead-lettered.'
        && $context['delivery_id'] === $this->delivery->id
        && ! str_contains(json_encode($context), 'payload'));
    Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'warning'
        && $context['reason'] === 'gone');
});
