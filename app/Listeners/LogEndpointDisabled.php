<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EndpointDisabledReason;
use App\Events\EndpointDisabled;
use Illuminate\Support\Facades\Log;

final class LogEndpointDisabled
{
    public function handle(EndpointDisabled $event): void
    {
        $level = $event->reason === EndpointDisabledReason::Manual ? 'info' : 'warning';

        Log::log($level, 'Webhook endpoint disabled.', [
            'endpoint_id' => $event->endpoint->id,
            'reason' => $event->reason->value,
            'consecutive_failures' => $event->endpoint->consecutive_failures,
        ]);
    }
}
