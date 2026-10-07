<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;

final class QueueDueDeliveries
{
    /**
     * Queues pending deliveries that are due, for active endpoints. This is
     * the safety net behind the delayed retry jobs (a lost job is picked up
     * here) and how a re-enabled endpoint's backlog starts moving.
     *
     * Deliveries whose job is still waiting on the queue are skipped by the
     * job's unique lock, so this never double-queues. Deleted endpoints are
     * included so their deliveries get marked dead.
     *
     * Returns how many due deliveries were found.
     */
    public function handle(): int
    {
        $deliveries = Delivery::query()
            ->where('status', DeliveryStatus::Pending)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereHas('endpoint', fn ($query) => $query->where('is_active', true))
            ->orderBy('next_attempt_at')
            ->limit(config()->integer('relay.sweep.batch_size'))
            ->get(['id', 'endpoint_id']);

        foreach ($deliveries as $delivery) {
            DeliverWebhook::dispatch($delivery);
        }

        return $deliveries->count();
    }
}
