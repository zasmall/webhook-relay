<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());
    $this->endpoint = Endpoint::factory()->create();
});

it('lists an endpoint\'s deliveries filtered by status, newest first', function () {
    $dead = Delivery::factory()->count(2)->status(DeliveryStatus::Dead)->create(['endpoint_id' => $this->endpoint->id]);
    Delivery::factory()->status(DeliveryStatus::Succeeded)->create(['endpoint_id' => $this->endpoint->id]);
    Delivery::factory()->status(DeliveryStatus::Dead)->create();

    $response = $this->getJson("/api/endpoints/{$this->endpoint->id}/deliveries?status=dead")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'dead')
        ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor']]);

    expect($response->json('data.*.id'))->toBe($dead->pluck('id')->sortDesc()->values()->all())
        ->and($response->json('data.0.event_type'))->toBeString();
});

it('rejects an unknown status filter', function () {
    $this->getJson("/api/endpoints/{$this->endpoint->id}/deliveries?status=lost")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('shows a delivery with its attempt log', function () {
    $delivery = Delivery::factory()->create(['endpoint_id' => $this->endpoint->id]);
    DeliveryAttempt::create(['delivery_id' => $delivery->id, 'attempt' => 1, 'request_headers' => ['X-Relay-Delivery-Id' => $delivery->id], 'status_code' => 500, 'response_body' => 'boom', 'duration_ms' => 12]);

    $this->getJson("/api/deliveries/{$delivery->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $delivery->id)
        ->assertJsonPath('data.attempt_log.0.status_code', 500)
        ->assertJsonPath('data.attempt_log.0.response_body', 'boom');
});

it('replays a single delivery', function () {
    $delivery = Delivery::factory()->status(DeliveryStatus::Dead)->create(['endpoint_id' => $this->endpoint->id]);

    $this->postJson("/api/deliveries/{$delivery->id}/replay")
        ->assertOk()
        ->assertExactJson(['replayed' => 1]);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('replays selected or all dead deliveries for an endpoint', function () {
    [$first, $second, $third] = Delivery::factory()->count(3)->status(DeliveryStatus::Dead)->create(['endpoint_id' => $this->endpoint->id]);

    $this->postJson("/api/endpoints/{$this->endpoint->id}/replay", ['delivery_ids' => [$first->id]])
        ->assertExactJson(['replayed' => 1]);

    $this->postJson("/api/endpoints/{$this->endpoint->id}/replay", ['all' => true])
        ->assertExactJson(['replayed' => 2]);

    expect($third->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('validates the replay request', function (array $body, string $field) {
    $this->postJson("/api/endpoints/{$this->endpoint->id}/replay", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'neither' => [[], 'delivery_ids'],
    'both' => [['all' => true, 'delivery_ids' => ['01m4cbsk8xqaq88efb6enjmhn4']], 'delivery_ids'],
    'all false' => [['all' => false], 'all'],
    'empty ids' => [['delivery_ids' => []], 'delivery_ids'],
    'not ulids' => [['delivery_ids' => ['nope']], 'delivery_ids.0'],
]);

it('returns 409 for a disabled endpoint', function () {
    $this->endpoint->forceFill(['is_active' => false])->save();
    Delivery::factory()->status(DeliveryStatus::Dead)->create(['endpoint_id' => $this->endpoint->id]);

    $this->postJson("/api/endpoints/{$this->endpoint->id}/replay", ['all' => true])
        ->assertConflict()
        ->assertJsonPath('message', "Endpoint {$this->endpoint->id} is disabled. Enable it before replaying deliveries.");
});

it('keeps source tokens out', function () {
    $token = Source::factory()->create()->createToken('ingest')->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($token)
        ->postJson("/api/endpoints/{$this->endpoint->id}/replay", ['all' => true])
        ->assertForbidden();
});
