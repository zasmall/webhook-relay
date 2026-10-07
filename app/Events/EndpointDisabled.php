<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\EndpointDisabledReason;
use App\Models\Endpoint;
use Illuminate\Foundation\Events\Dispatchable;

final class EndpointDisabled
{
    use Dispatchable;

    public function __construct(
        public readonly Endpoint $endpoint,
        public readonly EndpointDisabledReason $reason,
    ) {}
}
