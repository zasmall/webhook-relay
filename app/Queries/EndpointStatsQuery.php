<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\EndpointStats;
use App\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Delivery and attempt stats for many endpoints at once: two grouped
 * queries, however many endpoints there are.
 */
final class EndpointStatsQuery
{
    /**
     * @param  list<string>  $endpointIds
     * @return array<string, EndpointStats> keyed by endpoint id; every id is present
     */
    public function forEndpoints(array $endpointIds): array
    {
        if ($endpointIds === []) {
            return [];
        }

        $backlog = DB::table('deliveries')
            ->whereIn('endpoint_id', $endpointIds)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Dead->value])
            ->groupBy('endpoint_id')
            ->selectRaw('endpoint_id')
            ->selectRaw('sum(status = ?) as pending', [DeliveryStatus::Pending->value])
            ->selectRaw('sum(status = ?) as dead', [DeliveryStatus::Dead->value])
            ->get()
            ->keyBy('endpoint_id');

        $recent = DB::table('delivery_attempts')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_attempts.delivery_id')
            ->whereIn('deliveries.endpoint_id', $endpointIds)
            ->where('delivery_attempts.created_at', '>=', now()->subHours(config()->integer('relay.health.window_hours')))
            ->groupBy('deliveries.endpoint_id')
            ->selectRaw('deliveries.endpoint_id')
            ->selectRaw('count(*) as attempts')
            ->selectRaw('sum(delivery_attempts.status_code between 200 and 299) as successes')
            ->selectRaw('max(delivery_attempts.created_at) as last_attempt_at')
            ->get()
            ->keyBy('endpoint_id');

        $stats = [];

        foreach ($endpointIds as $id) {
            $b = $backlog->get($id);
            $r = $recent->get($id);

            $stats[$id] = new EndpointStats(
                pending: (int) ($b->pending ?? 0),
                dead: (int) ($b->dead ?? 0),
                recentAttempts: (int) ($r->attempts ?? 0),
                recentSuccesses: (int) ($r->successes ?? 0),
                lastAttemptAt: isset($r->last_attempt_at) ? CarbonImmutable::parse($r->last_attempt_at) : null,
            );
        }

        return $stats;
    }
}
