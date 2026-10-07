<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EndpointDisabledReason;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Endpoint>
 */
final class EndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => 'https://'.fake()->domainName().'/webhooks',
            'description' => fake()->sentence(3),
            'secret' => Endpoint::generateSecret(),
            'event_types' => ['*'],
            'is_active' => true,
        ];
    }

    public function disabled(EndpointDisabledReason $reason = EndpointDisabledReason::Manual): self
    {
        return $this->state([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => $reason,
        ]);
    }
}
