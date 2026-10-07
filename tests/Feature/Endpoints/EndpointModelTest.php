<?php

declare(strict_types=1);

use App\Models\Endpoint;
use Illuminate\Support\Facades\DB;

it('generates url-safe prefixed secrets', function () {
    $secret = Endpoint::generateSecret();

    expect($secret)->toMatch('/^whsec_[A-Za-z0-9_-]{43}$/')
        ->and(Endpoint::generateSecret())->not->toBe($secret);
});

it('encrypts secrets at rest', function () {
    $endpoint = Endpoint::factory()->create();

    $stored = DB::table('endpoints')->where('id', $endpoint->id)->value('secret');

    expect($stored)->not->toContain($endpoint->secret)
        ->and($endpoint->toArray())->not->toHaveKey('secret');
});

it('finds endpoints subscribed to a type in SQL', function () {
    $all = Endpoint::factory()->create(['event_types' => ['*']]);
    $exact = Endpoint::factory()->create(['event_types' => ['customer.updated', 'invoice.paid']]);
    $prefix = Endpoint::factory()->create(['event_types' => ['invoice.*']]);
    Endpoint::factory()->create(['event_types' => ['invoice.voided']]);
    Endpoint::factory()->create(['event_types' => ['invoices.*']]);

    $ids = Endpoint::subscribedTo('invoice.paid')->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$all->id, $exact->id, $prefix->id])->sort()->values()->all());
});

it('signs with the previous secret only during the grace window', function () {
    $endpoint = Endpoint::factory()->create([
        'previous_secret' => 'whsec_old',
        'previous_secret_expires_at' => now()->addHour(),
    ]);

    expect($endpoint->signingSecrets())->toBe([$endpoint->secret, 'whsec_old']);

    $this->travel(61)->minutes();

    expect($endpoint->signingSecrets())->toBe([$endpoint->secret]);
});
