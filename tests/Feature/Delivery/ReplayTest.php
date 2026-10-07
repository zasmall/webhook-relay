<?php

declare(strict_types=1);

use App\Actions\DisableEndpoint;
use App\Actions\ReplayDeliveries;
use App\Enums\DeliveryStatus;
use App\Exceptions\ReplayNotAllowed;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    $this->endpoint = Endpoint::factory()->create();
});

function deadDelivery(Endpoint $endpoint, int $attempts = 8): Delivery
{
    $delivery = Delivery::factory()->status(DeliveryStatus::Dead)->create(['endpoint_id' => $endpoint->id]);
    $delivery->forceFill(['attempts' => $attempts, 'last_status_code' => 500])->save();

    for ($n = 1; $n <= $attempts; $n++) {
        DeliveryAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt' => $n,
            'request_headers' => [],
            'status_code' => 500,
            'duration_ms' => 1,
        ]);
    }

    return $delivery;
}

it('replays every dead delivery for the endpoint', function () {
    $dead = collect([deadDelivery($this->endpoint), deadDelivery($this->endpoint)]);
    $succeeded = Delivery::factory()->status(DeliveryStatus::Succeeded)->create(['endpoint_id' => $this->endpoint->id]);
    $pending = Delivery::factory()->create(['endpoint_id' => $this->endpoint->id]);
    $otherEndpoint = deadDelivery(Endpoint::factory()->create());

    expect(app(ReplayDeliveries::class)->handle($this->endpoint))->toBe(2);

    foreach ($dead as $delivery) {
        expect($delivery->fresh())
            ->status->toBe(DeliveryStatus::Pending)
            ->attempts->toBe(0)
            ->next_attempt_at->toBeNull()
            ->replay_count->toBe(1)
            ->last_replayed_at->toEqual(now())
            ->and($delivery->attemptLog()->count())->toBe(8);
    }

    expect($succeeded->fresh()->status)->toBe(DeliveryStatus::Succeeded)
        ->and($pending->fresh()->replay_count)->toBe(0)
        ->and($otherEndpoint->fresh()->status)->toBe(DeliveryStatus::Dead);

    Queue::assertPushed(DeliverWebhook::class, 2);
    Queue::assertPushedOn('deliveries', DeliverWebhook::class);
});

it('replays only the selected dead deliveries', function () {
    $selected = deadDelivery($this->endpoint);
    $notSelected = deadDelivery($this->endpoint);
    $foreign = deadDelivery(Endpoint::factory()->create());
    $alive = Delivery::factory()->create(['endpoint_id' => $this->endpoint->id]);

    $replayed = app(ReplayDeliveries::class)->handle($this->endpoint, [$selected->id, $foreign->id, $alive->id]);

    expect($replayed)->toBe(1)
        ->and($selected->fresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($notSelected->fresh()->status)->toBe(DeliveryStatus::Dead)
        ->and($foreign->fresh()->status)->toBe(DeliveryStatus::Dead)
        ->and($alive->fresh()->replay_count)->toBe(0);
});

it('does nothing the second time', function () {
    deadDelivery($this->endpoint);
    $action = app(ReplayDeliveries::class);

    expect($action->handle($this->endpoint))->toBe(1)
        ->and($action->handle($this->endpoint))->toBe(0);
});

it('refuses to replay for a disabled or deleted endpoint', function (Closure $prepare) {
    deadDelivery($this->endpoint);
    $prepare($this->endpoint);

    expect(fn () => app(ReplayDeliveries::class)->handle($this->endpoint->fresh() ?? Endpoint::withTrashed()->find($this->endpoint->id)))
        ->toThrow(ReplayNotAllowed::class);

    Queue::assertNothingPushed();
})->with([
    'disabled' => [fn (Endpoint $endpoint) => app(DisableEndpoint::class)->handle($endpoint)],
    'deleted' => [fn (Endpoint $endpoint) => $endpoint->delete()],
]);

it('gets a fresh retry budget and continues the attempt numbering', function () {
    config(['relay.retry.max_attempts' => 3]);
    Http::preventStrayRequests();
    $delivery = deadDelivery($this->endpoint, attempts: 3);

    app(ReplayDeliveries::class)->handle($this->endpoint);

    // First attempt after the replay fails, second succeeds.
    Http::fakeSequence()->push('boom', 500)->push('ok', 200);

    $run = fn () => app()->call([new DeliverWebhook($delivery), 'handle']);
    $run();

    expect($delivery->fresh())
        ->status->toBe(DeliveryStatus::Pending) // 1 of 3 in this run, so it retries
        ->attempts->toBe(1);

    $this->travelTo($delivery->fresh()->next_attempt_at);
    $run();

    expect($delivery->fresh())
        ->status->toBe(DeliveryStatus::Succeeded)
        ->attempts->toBe(2)
        ->and($delivery->attemptLog()->pluck('status_code', 'attempt')->all())
        ->toBe([1 => 500, 2 => 500, 3 => 500, 4 => 500, 5 => 200]);
});
