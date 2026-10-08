<?php

declare(strict_types=1);

use App\Data\EndpointStats;
use App\Enums\EndpointHealth;

function health(bool $active = true, int $failures = 0, int $attempts = 0, int $successes = 0): EndpointHealth
{
    return EndpointHealth::classify(
        isActive: $active,
        consecutiveFailures: $failures,
        stats: new EndpointStats(recentAttempts: $attempts, recentSuccesses: $successes),
        breakerThreshold: 20,
        degradedBelow: 0.95,
        failingBelow: 0.5,
    );
}

it('classifies endpoint health', function (EndpointHealth $expected, array $args) {
    expect(health(...$args))->toBe($expected);
})->with([
    'disabled wins' => [EndpointHealth::Disabled, ['active' => false, 'attempts' => 100, 'successes' => 100]],
    'no recent attempts' => [EndpointHealth::Idle, []],
    'all good' => [EndpointHealth::Healthy, ['attempts' => 100, 'successes' => 100]],
    'exactly 95%' => [EndpointHealth::Healthy, ['attempts' => 100, 'successes' => 95]],
    'below 95%' => [EndpointHealth::Degraded, ['attempts' => 100, 'successes' => 94]],
    'a failure streak' => [EndpointHealth::Degraded, ['failures' => 1, 'attempts' => 100, 'successes' => 99]],
    'failing streak, idle otherwise' => [EndpointHealth::Degraded, ['failures' => 9]],
    'half the breaker threshold' => [EndpointHealth::Failing, ['failures' => 10]],
    'below 50%' => [EndpointHealth::Failing, ['attempts' => 10, 'successes' => 4]],
    'exactly 50%' => [EndpointHealth::Degraded, ['attempts' => 10, 'successes' => 5]],
]);

it('flags failing and disabled endpoints for attention', function () {
    expect(EndpointHealth::Failing->needsAttention())->toBeTrue()
        ->and(EndpointHealth::Disabled->needsAttention())->toBeTrue()
        ->and(EndpointHealth::Degraded->needsAttention())->toBeFalse()
        ->and(EndpointHealth::Healthy->needsAttention())->toBeFalse()
        ->and(EndpointHealth::Idle->needsAttention())->toBeFalse();
});
