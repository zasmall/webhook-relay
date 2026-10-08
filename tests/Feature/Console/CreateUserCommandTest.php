<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified operator', function () {
    $this->artisan('relay:user:create', ['name' => 'Ops', 'email' => 'ops@example.com', '--password' => 'correct-horse-battery'])
        ->expectsOutputToContain('Operator ops@example.com created.')
        ->assertSuccessful();

    $user = User::sole();

    expect($user->name)->toBe('Ops')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('prompts for the password when it is not given', function () {
    $this->artisan('relay:user:create', ['name' => 'Ops', 'email' => 'ops@example.com'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertSuccessful();
});

it('rejects invalid input', function (array $input, string $message) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('relay:user:create', [...['name' => 'Ops', 'email' => 'ops@example.com', '--password' => 'correct-horse-battery'], ...$input])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'taken email' => [['email' => 'taken@example.com'], 'email has already been taken'],
    'bad email' => [['email' => 'nope'], 'valid email'],
]);

it('rejects mismatched prompted passwords', function () {
    $this->artisan('relay:user:create', ['name' => 'Ops', 'email' => 'ops@example.com'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'something-else-entirely')
        ->expectsOutputToContain('confirmation does not match')
        ->assertFailed();
});
