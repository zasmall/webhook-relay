<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Enums\EndpointDisabledReason;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('summarises the last 24 hours and lists endpoints needing attention', function () {
    $this->freezeSecond();

    $healthy = Endpoint::factory()->create(['description' => 'Healthy']);
    $failing = Endpoint::factory()->create(['description' => 'Failing']);
    $failing->forceFill(['consecutive_failures' => 15])->save();
    $disabled = Endpoint::factory()->disabled(EndpointDisabledReason::CircuitBreaker)->create(['description' => 'Disabled']);

    Event::factory()->count(3)->create();
    $old = Event::factory()->create();
    $old->forceFill(['received_at' => now()->subDays(2)])->save();

    $ok = Delivery::factory()->status(DeliveryStatus::Succeeded)->create(['endpoint_id' => $healthy->id]);
    DeliveryAttempt::create(['delivery_id' => $ok->id, 'attempt' => 1, 'request_headers' => [], 'status_code' => 200, 'duration_ms' => 1]);
    $bad = Delivery::factory()->create(['endpoint_id' => $failing->id]);
    DeliveryAttempt::create(['delivery_id' => $bad->id, 'attempt' => 1, 'request_headers' => [], 'status_code' => 500, 'duration_ms' => 1]);
    DeliveryAttempt::create(['delivery_id' => $bad->id, 'attempt' => 2, 'request_headers' => [], 'status_code' => null, 'duration_ms' => 1]);
    Delivery::factory()->status(DeliveryStatus::Dead)->create(['endpoint_id' => $disabled->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('summary', [
                'window_hours' => 24,
                'events_received' => 3 + 3, // three standalone, three from the deliveries' factories
                'delivered' => 1,
                'failed_attempts' => 2,
                'dead_lettered' => 1,
                'pending' => 1,
            ])
            ->where('endpointCount', 3)
            ->has('needsAttention', 2)
            ->where('needsAttention.0.description', 'Failing')
            ->where('needsAttention.0.health', 'failing')
            ->where('needsAttention.1.description', 'Disabled')
            ->where('needsAttention.1.health', 'disabled'));
});
