<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class EndpointStats
{
    public function __construct(
        public int $pending = 0,
        public int $dead = 0,
        public int $recentAttempts = 0,
        public int $recentSuccesses = 0,
        public ?CarbonImmutable $lastAttemptAt = null,
    ) {}

    /**
     * Share of recent attempts that got a 2xx, or null with no recent attempts.
     */
    public function successRate(): ?float
    {
        return $this->recentAttempts === 0 ? null : $this->recentSuccesses / $this->recentAttempts;
    }
}
