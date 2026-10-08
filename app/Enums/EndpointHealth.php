<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\EndpointStats;

enum EndpointHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Disabled = 'disabled';

    /** No attempts in the window, so there's nothing to judge. */
    case Idle = 'idle';

    /**
     * Pure classification so the thresholds are easy to unit test.
     */
    public static function classify(
        bool $isActive,
        int $consecutiveFailures,
        EndpointStats $stats,
        int $breakerThreshold,
        float $degradedBelow,
        float $failingBelow,
    ): self {
        if (! $isActive) {
            return self::Disabled;
        }

        $rate = $stats->successRate();

        if ($consecutiveFailures * 2 >= $breakerThreshold || ($rate !== null && $rate < $failingBelow)) {
            return self::Failing;
        }

        if ($consecutiveFailures > 0 || ($rate !== null && $rate < $degradedBelow)) {
            return self::Degraded;
        }

        return $rate === null ? self::Idle : self::Healthy;
    }

    /**
     * Classify with the thresholds from config/relay.php.
     */
    public static function for(bool $isActive, int $consecutiveFailures, EndpointStats $stats): self
    {
        return self::classify(
            $isActive,
            $consecutiveFailures,
            $stats,
            config()->integer('relay.circuit_breaker.failure_threshold'),
            config()->float('relay.health.degraded_below'),
            config()->float('relay.health.failing_below'),
        );
    }

    public function needsAttention(): bool
    {
        return $this === self::Failing || $this === self::Disabled;
    }
}
