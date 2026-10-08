<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Relay-wide counts for the dashboard, over the health window.
 */
final class DashboardSummaryQuery
{
    /**
     * @return array{window_hours: int, events_received: int, delivered: int, failed_attempts: int, dead_lettered: int, pending: int}
     */
    public function get(): array
    {
        $windowHours = config()->integer('relay.health.window_hours');
        $since = now()->subHours($windowHours);

        $attempts = DB::table('delivery_attempts')
            ->where('created_at', '>=', $since)
            ->selectRaw('coalesce(sum(status_code between 200 and 299), 0) as delivered')
            ->selectRaw('coalesce(sum(status_code is null or status_code not between 200 and 299), 0) as failed')
            ->first();

        return [
            'window_hours' => $windowHours,
            'events_received' => Event::where('received_at', '>=', $since)->count(),
            'delivered' => (int) ($attempts->delivered ?? 0),
            'failed_attempts' => (int) ($attempts->failed ?? 0),
            // Dead rows aren't touched again until replayed, so updated_at is
            // when they died.
            'dead_lettered' => Delivery::where('status', DeliveryStatus::Dead)->where('updated_at', '>=', $since)->count(),
            'pending' => Delivery::where('status', DeliveryStatus::Pending)->count(),
        ];
    }
}
