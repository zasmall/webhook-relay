<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
final class DeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'endpoint_id' => Endpoint::factory(),
            'status' => DeliveryStatus::Pending,
        ];
    }

    public function status(DeliveryStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
