<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A delivery gave up: attempts ran out or the receiver answered 410 Gone.
 */
final class DeliveryDeadLettered
{
    use Dispatchable;

    public function __construct(
        public readonly Delivery $delivery,
    ) {}
}
