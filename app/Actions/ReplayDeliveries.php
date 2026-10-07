<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Exceptions\ReplayNotAllowed;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Collection;

final class ReplayDeliveries
{
    /**
     * Puts dead deliveries for the endpoint back in the queue with a fresh
     * retry budget. Pass delivery ids to replay a selection, or null for all.
     *
     * Only dead deliveries change, so ids that aren't dead (or belong to
     * another endpoint) are ignored and replaying twice is harmless. The
     * attempt log is kept; its numbering continues.
     *
     * Returns how many deliveries were replayed.
     *
     * @param  list<string>|null  $deliveryIds
     *
     * @throws ReplayNotAllowed
     */
    public function handle(Endpoint $endpoint, ?array $deliveryIds = null): int
    {
        if ($endpoint->trashed()) {
            throw ReplayNotAllowed::endpointDeleted($endpoint);
        }

        // A replay to a disabled endpoint would report success but send nothing.
        if (! $endpoint->is_active) {
            throw ReplayNotAllowed::endpointDisabled($endpoint);
        }

        $replayed = 0;

        $endpoint->deliveries()
            ->where('status', DeliveryStatus::Dead)
            ->when($deliveryIds !== null, fn ($query) => $query->whereIn('id', $deliveryIds ?? []))
            ->select(['id', 'endpoint_id'])
            ->chunkById(500, function (Collection $deliveries) use (&$replayed): void {
                $replayed += $this->replay($deliveries);
            });

        return $replayed;
    }

    /**
     * @param  Collection<int, Delivery>  $deliveries
     */
    private function replay(Collection $deliveries): int
    {
        // Conditional on "dead", so a concurrent replay can't double-count.
        $count = Delivery::whereKey($deliveries->modelKeys())
            ->where('status', DeliveryStatus::Dead)
            ->increment('replay_count', 1, [
                'status' => DeliveryStatus::Pending,
                'attempts' => 0,
                'next_attempt_at' => null,
                'last_replayed_at' => now(),
            ]);

        // A job for a delivery that wasn't replayed finds it not pending and
        // does nothing. If dispatch fails, relay:sweep picks them up.
        foreach ($deliveries as $delivery) {
            DeliverWebhook::dispatch($delivery);
        }

        return $count;
    }
}
