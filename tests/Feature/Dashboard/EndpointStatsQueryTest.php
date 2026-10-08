<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Queries\EndpointStatsQuery;

function attemptFor(Delivery $delivery, ?int $status, int $attempt = 1): void
{
    DeliveryAttempt::create([
        'delivery_id' => $delivery->id,
        'attempt' => $attempt,
        'request_headers' => [],
        'status_code' => $status,
        'duration_ms' => 5,
    ]);
}

it('counts backlog and recent attempts per endpoint', function () {
    $this->freezeSecond();
    $endpoint = Endpoint::factory()->create();
    $quiet = Endpoint::factory()->create();

    Delivery::factory()->count(2)->create(['endpoint_id' => $endpoint->id]);
    Delivery::factory()->status(DeliveryStatus::Dead)->create(['endpoint_id' => $endpoint->id]);
    $delivered = Delivery::factory()->status(DeliveryStatus::Succeeded)->create(['endpoint_id' => $endpoint->id]);

    $this->travel(-25)->hours();
    attemptFor($delivered, 500, 1); // outside the 24h window
    $this->travelBack();
    $this->freezeSecond();

    attemptFor($delivered, 500, 2);
    attemptFor($delivered, null, 3);
    attemptFor($delivered, 200, 4);
    attemptFor($delivered, 204, 5);

    $stats = app(EndpointStatsQuery::class)->forEndpoints([$endpoint->id, $quiet->id]);

    expect($stats[$endpoint->id])
        ->pending->toBe(2)
        ->dead->toBe(1)
        ->recentAttempts->toBe(4)
        ->recentSuccesses->toBe(2)
        ->and($stats[$endpoint->id]->successRate())->toBe(0.5)
        ->and($stats[$endpoint->id]->lastAttemptAt->equalTo(now()))->toBeTrue()
        ->and($stats[$quiet->id])
        ->pending->toBe(0)
        ->recentAttempts->toBe(0)
        ->lastAttemptAt->toBeNull()
        ->and($stats[$quiet->id]->successRate())->toBeNull();
});

it('returns nothing for no endpoints', function () {
    expect(app(EndpointStatsQuery::class)->forEndpoints([]))->toBe([]);
});
