<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Endpoint
 */
final class EndpointResource extends JsonResource
{
    private bool $withSecret = false;

    /**
     * Include the signing secret. Only used right after creation or rotation.
     */
    public function withSecret(): self
    {
        $this->withSecret = true;

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
        ];
    }
}
