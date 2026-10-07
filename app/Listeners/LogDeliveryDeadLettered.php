<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DeliveryDeadLettered;
use Illuminate\Support\Facades\Log;

final class LogDeliveryDeadLettered
{
    public function handle(DeliveryDeadLettered $event): void
    {
        $delivery = $event->delivery;

        // Ids and status only: never the payload.
        Log::warning('Webhook delivery dead-lettered.', [
            'delivery_id' => $delivery->id,
            'event_id' => $delivery->event_id,
            'endpoint_id' => $delivery->endpoint_id,
            'attempts' => $delivery->attempts,
            'last_status_code' => $delivery->last_status_code,
        ]);
    }
}
