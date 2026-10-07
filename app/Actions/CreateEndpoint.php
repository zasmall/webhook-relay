<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Endpoint;

final class CreateEndpoint
{
    /**
     * @param  list<string>  $eventTypes
     */
    public function handle(string $url, array $eventTypes, ?string $description = null): Endpoint
    {
        $endpoint = new Endpoint([
            'url' => $url,
            'description' => $description,
            'event_types' => array_values(array_unique($eventTypes)),
        ]);

        $endpoint->secret = Endpoint::generateSecret();
        $endpoint->save();

        return $endpoint;
    }
}
