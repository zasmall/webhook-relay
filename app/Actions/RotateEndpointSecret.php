<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Endpoint;

final class RotateEndpointSecret
{
    /**
     * Issues a new secret and keeps the old one signing deliveries for the
     * grace window, so receivers can switch without rejecting webhooks.
     *
     * Only one previous secret is kept: rotating again during the window
     * drops the oldest secret immediately.
     */
    public function handle(Endpoint $endpoint): Endpoint
    {
        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => now()->addSeconds(config()->integer('relay.endpoints.secret_rotation_grace')),
            'secret' => Endpoint::generateSecret(),
        ])->save();

        return $endpoint;
    }
}
