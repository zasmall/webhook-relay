<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EndpointDisabledReason;
use App\Models\Endpoint;

final class DisableEndpoint
{
    /**
     * Pending deliveries for a disabled endpoint stay pending until it is
     * re-enabled.
     */
    public function handle(Endpoint $endpoint, EndpointDisabledReason $reason = EndpointDisabledReason::Manual): void
    {
        if (! $endpoint->is_active) {
            return;
        }

        $endpoint->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => $reason,
        ])->save();
    }
}
