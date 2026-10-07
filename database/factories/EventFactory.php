<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
final class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'type' => fake()->randomElement(['invoice.paid', 'invoice.created', 'customer.updated']),
            'payload' => (object) ['id' => fake()->uuid(), 'amount' => fake()->numberBetween(100, 100_000)],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
