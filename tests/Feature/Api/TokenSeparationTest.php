<?php

declare(strict_types=1);

use App\Models\Source;
use App\Models\User;

it('rejects source tokens on endpoint management', function () {
    $token = Source::factory()->create()->createToken('ingest')->plainTextToken;

    $this->withToken($token)->getJson('/api/endpoints')->assertForbidden();
});

it('rejects user tokens on ingest', function () {
    $token = User::factory()->create()->createToken('cli')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/events', ['type' => 'invoice.paid', 'payload' => ['id' => 1]], ['Idempotency-Key' => 'k'])
        ->assertForbidden();
});

it('requires a token for endpoint management', function () {
    $this->getJson('/api/endpoints')->assertUnauthorized();
});
