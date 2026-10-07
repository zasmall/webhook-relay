<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Endpoint;

final class EnableEndpoint
{
    /**
     * Re-enabling clears breaker state, so pending deliveries resume.
     */
    public function handle(Endpoint $endpoint): void
    {
        if ($endpoint->is_active) {
            return;
        }

        $endpoint->forceFill([
            'is_active' => true,
            'consecutive_failures' => 0,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();
    }
}
