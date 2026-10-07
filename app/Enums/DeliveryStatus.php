<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryStatus: string
{
    /** Waiting to be sent: new, waiting for a retry, or its endpoint is disabled. */
    case Pending = 'pending';

    /** Claimed by a worker; an HTTP request is in flight. */
    case Delivering = 'delivering';

    case Succeeded = 'succeeded';

    /** Gave up: attempts exhausted, endpoint gone, or endpoint deleted. */
    case Dead = 'dead';
}
