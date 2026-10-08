<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReplaySummary
{
    public function __construct(
        public int $replayed = 0,
        /** Selected deliveries whose endpoint is disabled or deleted. */
        public int $skipped = 0,
    ) {}
}
