<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets signed-in operators see Horizon, and nobody else', function () {
    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});
