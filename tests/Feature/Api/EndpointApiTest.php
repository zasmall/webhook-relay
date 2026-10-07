<?php

declare(strict_types=1);

use App\Enums\EndpointDisabledReason;
use App\Models\Endpoint;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists endpoints without secrets', function () {
    Endpoint::factory()->count(2)->create();

    $response = $this->getJson('/api/endpoints')->assertOk()->assertJsonCount(2, 'data');

    expect($response->json('data.0'))->not->toHaveKey('secret');
});

it('creates an endpoint and returns its secret once', function () {
    $response = $this->postJson('/api/endpoints', [
        'url' => 'https://hooks.example.com/relay',
        'description' => 'Billing',
        'event_types' => ['invoice.*', 'customer.updated'],
    ])->assertCreated();

    $endpoint = Endpoint::sole();

    $response->assertJsonPath('data.id', $endpoint->id)
        ->assertJsonPath('data.secret', $endpoint->secret)
        ->assertJsonPath('data.event_types', ['invoice.*', 'customer.updated'])
        ->assertJsonPath('data.is_active', true);

    expect($endpoint->secret)->toStartWith('whsec_');

    $this->getJson("/api/endpoints/{$endpoint->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.secret');
});

it('ignores a secret supplied by the caller', function () {
    $this->postJson('/api/endpoints', [
        'url' => 'https://hooks.example.com/relay',
        'event_types' => ['*'],
        'secret' => 'whsec_chosen',
    ])->assertCreated();

    expect(Endpoint::sole()->secret)->not->toBe('whsec_chosen');
});

it('validates endpoints', function (array $overrides, string $field) {
    $this->postJson('/api/endpoints', array_merge([
        'url' => 'https://hooks.example.com/relay',
        'event_types' => ['*'],
    ], $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing url' => [['url' => null], 'url'],
    'not a url' => [['url' => 'not a url'], 'url'],
    'private url' => [['url' => 'https://10.0.0.1/hook'], 'url'],
    'http url' => [['url' => 'http://hooks.example.com'], 'url'],
    'no event types' => [['event_types' => []], 'event_types'],
    'event types not a list' => [['event_types' => ['a' => 'invoice.paid']], 'event_types'],
    'bad pattern' => [['event_types' => ['invoice.*.paid']], 'event_types.0'],
    'duplicate pattern' => [['event_types' => ['*', '*']], 'event_types.0'],
    'description too long' => [['description' => str_repeat('d', 256)], 'description'],
]);

it('limits the number of event types', function () {
    config(['relay.endpoints.max_event_types' => 2]);

    $this->postJson('/api/endpoints', [
        'url' => 'https://hooks.example.com/relay',
        'event_types' => ['a', 'b', 'c'],
    ])->assertJsonValidationErrors('event_types');
});

it('partially updates an endpoint', function () {
    $endpoint = Endpoint::factory()->create(['description' => 'Old', 'event_types' => ['*']]);

    $this->patchJson("/api/endpoints/{$endpoint->id}", ['event_types' => ['invoice.paid']])
        ->assertOk()
        ->assertJsonPath('data.event_types', ['invoice.paid'])
        ->assertJsonPath('data.description', 'Old');
});

it('disables an endpoint manually', function () {
    $endpoint = Endpoint::factory()->create();

    $this->patchJson("/api/endpoints/{$endpoint->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.disabled_reason', 'manual');

    expect($endpoint->fresh()->disabled_at)->not->toBeNull();
});

it('clears breaker state when re-enabled', function () {
    $endpoint = Endpoint::factory()
        ->disabled(EndpointDisabledReason::CircuitBreaker)
        ->create(['consecutive_failures' => 20]);

    $this->patchJson("/api/endpoints/{$endpoint->id}", ['is_active' => true])->assertOk();

    expect($endpoint->fresh())
        ->is_active->toBeTrue()
        ->consecutive_failures->toBe(0)
        ->disabled_at->toBeNull()
        ->disabled_reason->toBeNull();
});

it('rotates the secret with a grace window', function () {
    $this->freezeSecond();
    $endpoint = Endpoint::factory()->create();
    $oldSecret = $endpoint->secret;

    $response = $this->postJson("/api/endpoints/{$endpoint->id}/rotate-secret")->assertOk();

    $endpoint->refresh();

    expect($endpoint->secret)->not->toBe($oldSecret)
        ->and($endpoint->previous_secret)->toBe($oldSecret)
        ->and($endpoint->previous_secret_expires_at->equalTo(now()->addDay()))->toBeTrue()
        ->and($endpoint->signingSecrets())->toBe([$endpoint->secret, $oldSecret]);

    $response->assertJsonPath('data.secret', $endpoint->secret)
        ->assertJsonPath('data.previous_secret_expires_at', now()->addDay()->toIso8601ZuluString());
});

it('soft deletes an endpoint', function () {
    $endpoint = Endpoint::factory()->create();

    $this->deleteJson("/api/endpoints/{$endpoint->id}")->assertNoContent();

    $this->assertSoftDeleted($endpoint);
    $this->getJson("/api/endpoints/{$endpoint->id}")->assertNotFound();
});
