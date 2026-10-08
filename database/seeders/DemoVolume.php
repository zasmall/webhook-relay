<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Models\Endpoint;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk history for checking query plans at realistic volume: old,
 * mostly-succeeded deliveries spread over 90 days, older than the dashboard's
 * 24-hour window so the demo's live numbers are unchanged.
 */
final class DemoVolume
{
    private const CHUNK = 2000;

    private const TYPES = ['invoice.created', 'invoice.paid', 'order.created', 'order.shipped', 'customer.updated'];

    public function add(int $count): void
    {
        $sourceIds = Source::pluck('id')->all();
        $endpointIds = Endpoint::pluck('id')->all();
        $start = CarbonImmutable::now()->subDays(98);
        $span = 90 * 86_400;

        for ($done = 0; $done < $count; $done += self::CHUNK) {
            $events = $deliveries = $attempts = [];

            for ($i = $done; $i < min($done + self::CHUNK, $count); $i++) {
                $at = $start->addSeconds(random_int(0, $span));
                $eventId = strtolower((string) Str::ulid($at));
                $deliveryId = strtolower((string) Str::ulid($at));
                $ok = random_int(1, 100) <= 93;
                $type = self::TYPES[array_rand(self::TYPES)];

                $events[] = ['id' => $eventId, 'source_id' => $sourceIds[array_rand($sourceIds)], 'type' => $type, 'payload' => '{"bulk":true}', 'idempotency_key' => "volume-{$i}", 'received_at' => $at];
                $deliveries[] = ['id' => $deliveryId, 'event_id' => $eventId, 'endpoint_id' => $endpointIds[array_rand($endpointIds)], 'status' => ($ok ? DeliveryStatus::Succeeded : DeliveryStatus::Dead)->value, 'attempts' => 1, 'next_attempt_at' => null, 'last_status_code' => $ok ? 200 : 500, 'delivered_at' => $ok ? $at : null, 'replay_count' => 0, 'created_at' => $at, 'updated_at' => $at];
                $attempts[] = ['delivery_id' => $deliveryId, 'attempt' => 1, 'request_headers' => '{}', 'status_code' => $ok ? 200 : 500, 'response_body' => null, 'error' => null, 'duration_ms' => random_int(15, 200), 'created_at' => $at];
            }

            DB::table('events')->insert($events);
            DB::table('deliveries')->insert($deliveries);
            DB::table('delivery_attempts')->insert($attempts);
        }
    }
}
