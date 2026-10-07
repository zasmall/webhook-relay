<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Endpoint;

final class UpdateEndpoint
{
    public function __construct(
        private readonly EnableEndpoint $enableEndpoint,
        private readonly DisableEndpoint $disableEndpoint,
    ) {}

    /**
     * Applies only the attributes present in $attributes, so callers can send a
     * partial update (e.g. just is_active from a toggle).
     *
     * @param  array{url?: string, description?: string|null, event_types?: list<string>, is_active?: bool}  $attributes
     */
    public function handle(Endpoint $endpoint, array $attributes): Endpoint
    {
        if (array_key_exists('event_types', $attributes)) {
            $attributes['event_types'] = array_values(array_unique($attributes['event_types']));
        }

        $endpoint->fill(array_intersect_key($attributes, array_flip(['url', 'description', 'event_types'])));
        $endpoint->save();

        if (array_key_exists('is_active', $attributes)) {
            $attributes['is_active']
                ? $this->enableEndpoint->handle($endpoint)
                : $this->disableEndpoint->handle($endpoint);
        }

        return $endpoint;
    }
}
