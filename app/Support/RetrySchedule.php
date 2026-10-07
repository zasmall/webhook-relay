<?php

declare(strict_types=1);

namespace App\Support;

use Random\Randomizer;

/**
 * Exponential backoff with full jitter: the delay before the next attempt is
 * a random number of seconds between 0 and min(maxDelay, baseDelay * 2^(n-1)),
 * where n is the number of attempts made so far.
 *
 * Full jitter spreads retries out, so receivers recovering from an outage
 * aren't hit by every queued delivery at the same moment.
 */
final class RetrySchedule
{
    public function __construct(
        private readonly int $baseDelay,
        private readonly int $maxDelay,
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * @param  int<1, max>  $attemptsMade
     */
    public function delayFor(int $attemptsMade): int
    {
        return $this->randomizer->getInt(0, $this->ceiling($attemptsMade));
    }

    /**
     * The largest possible delay after the given number of attempts.
     *
     * @param  int<1, max>  $attemptsMade
     */
    public function ceiling(int $attemptsMade): int
    {
        // Capping the exponent keeps 2^n from overflowing on huge inputs.
        $exponent = min($attemptsMade - 1, 30);

        return min($this->maxDelay, $this->baseDelay * (2 ** $exponent));
    }
}
