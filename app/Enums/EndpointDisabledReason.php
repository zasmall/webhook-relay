<?php

declare(strict_types=1);

namespace App\Enums;

enum EndpointDisabledReason: string
{
    /** An operator turned the endpoint off. */
    case Manual = 'manual';

    /** Too many consecutive delivery failures tripped the circuit breaker. */
    case CircuitBreaker = 'circuit_breaker';

    /** The receiver answered 410 Gone. */
    case Gone = 'gone';
}
