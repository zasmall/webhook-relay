<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the relay reacts to one attempt's result.
 */
enum DeliveryOutcome
{
    /** 2xx: done. */
    case Succeeded;

    /** 410: the receiver says the endpoint is gone for good. */
    case Gone;

    /** 429: the receiver is up but busy; retry later without blaming it. */
    case RateLimited;

    /** Anything else, including timeouts and refused addresses: retry. */
    case Failed;

    public static function fromStatus(?int $status): self
    {
        return match (true) {
            $status === null => self::Failed,
            $status >= 200 && $status < 300 => self::Succeeded,
            $status === 410 => self::Gone,
            $status === 429 => self::RateLimited,
            default => self::Failed,
        };
    }
}
