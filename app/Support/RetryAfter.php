<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Parses a Retry-After header: either delay-seconds ("120") or an HTTP date
 * ("Wed, 21 Oct 2026 07:28:00 GMT").
 */
final class RetryAfter
{
    /**
     * Seconds to wait, clamped to [0, $max], or null if the header is missing
     * or unreadable.
     */
    public static function seconds(?string $header, DateTimeInterface $now, int $max): ?int
    {
        $header = trim((string) $header);

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return min((int) $header, $max);
        }

        try {
            $date = CarbonImmutable::createFromFormat(DateTimeInterface::RFC7231, $header, 'GMT');
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return max(0, min($date->getTimestamp() - $now->getTimestamp(), $max));
    }
}
