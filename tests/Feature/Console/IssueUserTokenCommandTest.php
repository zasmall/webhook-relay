<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('issues a token that can manage endpoints', function () {
    User::factory()->create(['email' => 'ops@example.com']);

    expect(Artisan::call('relay:user:token', ['email' => 'ops@example.com']))->toBe(0);

    preg_match('/^\d+\|\S+$/m', Artisan::output(), $matches);
    expect($matches)->toHaveCount(1);

    $this->withToken($matches[0])->getJson('/api/endpoints')->assertOk();
});

it('fails for an unknown email', function () {
    $this->artisan('relay:user:token', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user has that email address.')
        ->assertFailed();
});
