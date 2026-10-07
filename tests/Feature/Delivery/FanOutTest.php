<?php

declare(strict_types=1);

use App\Actions\CreateDeliveries;
use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\FanOutEvent;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Event;
use App\Models\Source;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(now()->subMinute());
});

function eventOfType(string $type): Event
{
    test()->travelBack();

    return Event::factory()->create(['type' => $type]);
}

it('creates a delivery for each active subscribed endpoint', function () {
    $all = Endpoint::factory()->create(['event_types' => ['*']]);
    $prefix = Endpoint::factory()->create(['event_types' => ['invoice.*']]);
    Endpoint::factory()->create(['event_types' => ['customer.*']]);
    Endpoint::factory()->disabled()->create(['event_types' => ['*']]);
    Endpoint::factory()->create(['event_types' => ['*']])->delete();

    Queue::fake();
    $event = eventOfType('invoice.paid');

    (new FanOutEvent($event))->handle(app(CreateDeliveries::class));

    expect($event->deliveries()->pluck('endpoint_id')->sort()->values()->all())
        ->toBe(collect([$all->id, $prefix->id])->sort()->values()->all());

    Queue::assertPushedOn('deliveries', DeliverWebhook::class);
    Queue::assertPushed(DeliverWebhook::class, 2);
});

it('is safe to run more than once', function () {
    Endpoint::factory()->count(2)->create();

    Queue::fake();
    $event = eventOfType('invoice.paid');
    $job = new FanOutEvent($event);

    $job->handle(app(CreateDeliveries::class));
    $job->handle(app(CreateDeliveries::class));

    expect(Delivery::count())->toBe(2);
});

it('only re-queues deliveries that are ready to send', function () {
    Endpoint::factory()->count(3)->create();

    Queue::fake();
    $event = eventOfType('invoice.paid');
    app(CreateDeliveries::class)->handle($event);

    [$succeeded, $waiting] = $event->deliveries()->get();
    $succeeded->update(['status' => DeliveryStatus::Succeeded]);
    $waiting->forceFill(['next_attempt_at' => now()->addHour()])->save();

    (new FanOutEvent($event))->handle(app(CreateDeliveries::class));

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('ignores endpoints created after the event was received', function () {
    Queue::fake();
    $event = eventOfType('invoice.paid');

    $this->travel(1)->minute();
    Endpoint::factory()->create();

    (new FanOutEvent($event))->handle(app(CreateDeliveries::class));

    expect(Delivery::count())->toBe(0);
});

it('is dispatched on the fanout queue for new and duplicate events', function () {
    $this->travelBack();
    Queue::fake();
    Sanctum::actingAs(Source::factory()->create());

    $body = ['type' => 'invoice.paid', 'payload' => ['id' => 1]];

    $this->postJson('/api/events', $body, ['Idempotency-Key' => 'k'])->assertAccepted();
    $this->postJson('/api/events', $body, ['Idempotency-Key' => 'k'])->assertOk();

    Queue::assertPushedOn('fanout', FanOutEvent::class);
    Queue::assertPushed(FanOutEvent::class, 2);
    Queue::assertPushed(FanOutEvent::class, fn (FanOutEvent $job) => $job->event->is(Event::sole()));
});
