<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

it('shows a delivery with its attempts and payload', function () {
    $event = Event::factory()->create(['type' => 'invoice.paid', 'payload' => (object) ['id' => 'inv_1', 'meta' => (object) []]]);
    $delivery = Delivery::factory()->status(DeliveryStatus::Dead)->create(['event_id' => $event->id]);
    DeliveryAttempt::create(['delivery_id' => $delivery->id, 'attempt' => 1, 'request_headers' => ['X-Relay-Delivery-Id' => $delivery->id], 'status_code' => 500, 'response_body' => 'boom', 'duration_ms' => 42]);
    DeliveryAttempt::create(['delivery_id' => $delivery->id, 'attempt' => 2, 'request_headers' => [], 'error' => 'Connection timed out', 'duration_ms' => 10_000]);

    $this->get(route('deliveries.show', $delivery))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('deliveries/Show')
            ->where('delivery.id', $delivery->id)
            ->where('delivery.status', 'dead')
            ->has('delivery.attempt_log', 2)
            ->where('delivery.attempt_log.0.response_body', 'boom')
            ->where('delivery.attempt_log.1.error', 'Connection timed out')
            ->where('event.type', 'invoice.paid')
            ->where('event.payload', fn (string $payload) => str_contains($payload, '"meta": {}'))
            ->where('endpointActive', true));
});

it('replays a single dead delivery', function () {
    $delivery = Delivery::factory()->status(DeliveryStatus::Dead)->create();

    $this->from(route('deliveries.show', $delivery))
        ->post(route('deliveries.replay', $delivery))
        ->assertRedirect(route('deliveries.show', $delivery))
        ->assertInertiaFlash('toast.type', 'success');

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('replays a selection across endpoints and reports skips', function () {
    $active = Endpoint::factory()->create();
    $disabled = Endpoint::factory()->disabled()->create();

    $replayable = Delivery::factory()->count(2)->status(DeliveryStatus::Dead)->create(['endpoint_id' => $active->id]);
    $blocked = Delivery::factory()->status(DeliveryStatus::Dead)->create(['endpoint_id' => $disabled->id]);

    $this->from(route('deliveries.index'))
        ->post(route('deliveries.replay-selected'), [
            'delivery_ids' => [...$replayable->modelKeys(), $blocked->id],
        ])
        ->assertRedirect(route('deliveries.index'))
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Replaying 2 deliveries. Skipped 1: its endpoint is disabled or deleted.');

    expect($blocked->fresh()->status)->toBe(DeliveryStatus::Dead);
});

it('requires a selection', function () {
    $this->post(route('deliveries.replay-selected'), ['all' => true])
        ->assertSessionHasErrors('delivery_ids');
});
