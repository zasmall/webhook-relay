<?php

declare(strict_types=1);

use App\Actions\EnableEndpoint;
use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
});

it('resets deliveries stuck in delivering', function () {
    config(['relay.sweep.stale_after' => 120]);

    $stuck = Delivery::factory()->status(DeliveryStatus::Delivering)->create(['updated_at' => now()->subSeconds(121)]);
    $inFlight = Delivery::factory()->status(DeliveryStatus::Delivering)->create(['updated_at' => now()->subSeconds(60)]);

    $this->artisan('relay:sweep')->expectsOutputToContain('Reset 1 stuck')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($inFlight->fresh()->status)->toBe(DeliveryStatus::Delivering);
});

it('queues pending deliveries that are due', function () {
    $new = Delivery::factory()->create();
    $due = Delivery::factory()->create();
    $due->forceFill(['next_attempt_at' => now()->subMinute()])->save();
    $notDue = Delivery::factory()->create();
    $notDue->forceFill(['next_attempt_at' => now()->addMinute()])->save();
    Delivery::factory()->status(DeliveryStatus::Succeeded)->create();
    Delivery::factory()->status(DeliveryStatus::Dead)->create();
    Delivery::factory()->create(['endpoint_id' => Endpoint::factory()->disabled()]);

    $this->artisan('relay:sweep')->expectsOutputToContain('found 2 due')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 2);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $new->id);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $due->id);
});

it('queues deliveries for deleted endpoints so they get marked dead', function () {
    $delivery = Delivery::factory()->create();
    $delivery->endpoint->delete();

    $this->artisan('relay:sweep')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);
});

it('picks up a re-enabled endpoint backlog', function () {
    $endpoint = Endpoint::factory()->disabled()->create();
    Delivery::factory()->count(3)->create(['endpoint_id' => $endpoint->id]);

    $this->artisan('relay:sweep');
    Queue::assertNothingPushed();

    app(EnableEndpoint::class)->handle($endpoint);
    $this->artisan('relay:sweep');

    Queue::assertPushed(DeliverWebhook::class, 3);
});

it('limits each run to the batch size, oldest due first', function () {
    config(['relay.sweep.batch_size' => 2]);

    $deliveries = collect([30, 10, 20])->map(function (int $minutesAgo) {
        $delivery = Delivery::factory()->create();
        $delivery->forceFill(['next_attempt_at' => now()->subMinutes($minutesAgo)])->save();

        return $delivery;
    });

    $this->artisan('relay:sweep');

    Queue::assertPushed(DeliverWebhook::class, 2);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $deliveries[0]->id);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $deliveries[2]->id);
});

it('runs every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'relay:sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
