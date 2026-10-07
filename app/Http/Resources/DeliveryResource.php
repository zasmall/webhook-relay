<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Delivery
 */
final class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'endpoint_id' => $this->endpoint_id,
            'event_type' => $this->whenLoaded('event', fn () => $this->event->type),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601ZuluString(),
            'last_status_code' => $this->last_status_code,
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'replay_count' => $this->replay_count,
            'last_replayed_at' => $this->last_replayed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'attempt_log' => DeliveryAttemptResource::collection($this->whenLoaded('attemptLog')),
        ];
    }
}
