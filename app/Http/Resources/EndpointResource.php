<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\EndpointStats;
use App\Enums\EndpointHealth;
use App\Models\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Endpoint
 */
final class EndpointResource extends JsonResource
{
    private bool $withSecret = false;

    private ?EndpointStats $stats = null;

    /**
     * Include the signing secret. Only used right after creation or rotation.
     */
    public function withSecret(): self
    {
        $this->withSecret = true;

        return $this;
    }

    /**
     * Include delivery stats and the derived health label.
     */
    public function withStats(EndpointStats $stats): self
    {
        $this->stats = $stats;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'event_types' => $this->event_types,
            'is_active' => $this->is_active,
            'consecutive_failures' => $this->consecutive_failures,
            'disabled_at' => $this->disabled_at?->toIso8601ZuluString(),
            'disabled_reason' => $this->disabled_reason?->value,
            'previous_secret_expires_at' => $this->previous_secret_expires_at?->isFuture()
                ? $this->previous_secret_expires_at->toIso8601ZuluString()
                : null,
            'secret' => $this->when($this->withSecret, fn () => $this->secret),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'deleted_at' => $this->deleted_at?->toIso8601ZuluString(),
            'health' => $this->when($this->stats !== null, fn () => EndpointHealth::for($this->is_active, $this->consecutive_failures, $this->stats ?? new EndpointStats)->value),
            'stats' => $this->when($this->stats !== null, fn () => [
                'pending' => $this->stats?->pending,
                'dead' => $this->stats?->dead,
                'recent_attempts' => $this->stats?->recentAttempts,
                'success_rate' => $this->stats?->successRate(),
                'last_attempt_at' => $this->stats?->lastAttemptAt?->toIso8601ZuluString(),
            ]),
        ];
    }
}
