<?php

declare(strict_types=1);

use App\Models\User;

it('has no public sign-up', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertNotFound();

    expect(User::count())->toBe(0);
});
