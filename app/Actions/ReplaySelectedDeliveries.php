<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ReplaySummary;
use App\Exceptions\ReplayNotAllowed;
use App\Models\Delivery;
use App\Models\Endpoint;

/**
 * Replays a selection that may span endpoints: groups the ids by endpoint and
 * replays each group. Groups whose endpoint can't be replayed (disabled or
 * deleted) are skipped and counted, rather than failing the whole selection.
 */
final class ReplaySelectedDeliveries
{
    public function __construct(
        private readonly ReplayDeliveries $replayDeliveries,
    ) {}

    /**
     * @param  list<string>  $deliveryIds
     */
    public function handle(array $deliveryIds): ReplaySummary
    {
        $groups = Delivery::whereKey($deliveryIds)->get(['id', 'endpoint_id'])->groupBy('endpoint_id');
        $endpoints = Endpoint::withTrashed()->whereKey($groups->keys())->get()->keyBy('id');

        $replayed = 0;
        $skipped = 0;

        foreach ($groups as $endpointId => $deliveries) {
            $ids = array_values($deliveries->modelKeys());
            $endpoint = $endpoints->get($endpointId);

            if ($endpoint === null) {
                $skipped += count($ids);

                continue;
            }

            try {
                $replayed += $this->replayDeliveries->handle($endpoint, $ids);
            } catch (ReplayNotAllowed) {
                $skipped += count($ids);
            }
        }

        return new ReplaySummary($replayed, $skipped);
    }
}
