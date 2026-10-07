<?php

declare(strict_types=1);

use App\Models\Source;
use Illuminate\Support\Facades\Artisan;

it('creates a source and prints a working token', function () {
    expect(Artisan::call('relay:source:create', ['name' => 'billing']))->toBe(0);

    $source = Source::sole();
    expect($source->name)->toBe('billing');

    preg_match('/^\d+\|\S+$/m', Artisan::output(), $matches);
    expect($matches)->toHaveCount(1);

    $this->withToken($matches[0])
        ->postJson('/api/events', ['type' => 'invoice.paid', 'payload' => ['id' => 1]], ['Idempotency-Key' => 'k'])
        ->assertAccepted();
});

it('rejects a duplicate name', function () {
    Source::factory()->create(['name' => 'billing']);

    $this->artisan('relay:source:create', ['name' => 'billing'])
        ->expectsOutputToContain('The name has already been taken.')
        ->assertFailed();

    expect(Source::count())->toBe(1);
});
