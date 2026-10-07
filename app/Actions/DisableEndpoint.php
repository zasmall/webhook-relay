<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EndpointDisabledReason;
use App\Events\EndpointDisabled;
use App\Models\Endpoint;

final class DisableEndpoint
{
    /**
     * Pending deliveries for a disabled endpoint stay pending until it is
     * re-enabled.
     *
     * The update only applies to an active endpoint, so when several workers
     * trip the breaker at once, exactly one disables it and fires the event.
     * Returns whether this call disabled it.
     */
    public function handle(Endpoint $endpoint, EndpointDisabledReason $reason = EndpointDisabledReason::Manual): bool
    {
        $attributes = [
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => $reason,
        ];

        $disabled = Endpoint::whereKey($endpoint->id)->where('is_active', true)->update($attributes) === 1;

        if ($disabled) {
            $endpoint->forceFill($attributes)->syncOriginal();

            EndpointDisabled::dispatch($endpoint, $reason);
        }

        return $disabled;
    }
}
