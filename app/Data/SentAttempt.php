<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\DeliveryAttempt;

final readonly class SentAttempt
{
    public function __construct(
        public DeliveryAttempt $attempt,
        public ?string $retryAfter = null,
    ) {}
}
