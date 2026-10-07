<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class CreateDeliveries
{
    /**
     * Creates one delivery per active, subscribed endpoint and returns the
     * event's deliveries that are ready to send.
     *
     * Safe to run any number of times: insertOrIgnore skips deliveries that
     * already exist. Only endpoints that existed when the event was received
     * get one, so a re-run never sends old events to newer endpoints.
     *
     * @return Collection<int, Delivery>
     */
    public function handle(Event $event): Collection
    {
        Endpoint::query()
            ->active()
            ->subscribedTo($event->type)
            ->where('created_at', '<=', $event->received_at)
            ->select('id')
            ->chunkById(500, function ($endpoints) use ($event): void {
                $now = now();

                Delivery::insertOrIgnore($endpoints->map(fn (Endpoint $endpoint): array => [
                    'id' => strtolower((string) Str::ulid()),
                    'event_id' => $event->id,
                    'endpoint_id' => $endpoint->id,
                    'status' => DeliveryStatus::Pending->value,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        return $event->deliveries()
            ->where('status', DeliveryStatus::Pending)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->get();
    }
}
