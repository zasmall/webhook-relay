<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;

final class ResetStaleDeliveries
{
    /**
     * Puts deliveries stuck in "delivering" back to pending. That happens when
     * a worker is killed mid-request, before the job's failed() hook can run.
     * The request may have reached the receiver, so it may be sent again;
     * delivery is at-least-once and receivers dedupe on the event id.
     *
     * Returns how many were reset.
     */
    public function handle(): int
    {
        return Delivery::where('status', DeliveryStatus::Delivering)
            ->where('updated_at', '<', now()->subSeconds(config()->integer('relay.sweep.stale_after')))
            ->update(['status' => DeliveryStatus::Pending, 'updated_at' => now()]);
    }
}
