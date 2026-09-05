<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'conversation_id' => null,
            'customer_name' => fake()->name(),
            'phone' => '+9929'.fake()->numerify('#######'),
            'contact' => fake()->phoneNumber(),
            'items' => fake()->words(3, true),
            'note' => fake()->optional()->sentence(),
            'source' => fake()->randomElement(['telegram', 'whatsapp', 'instagram', 'playground']),
            'status' => 'new',
            'seen_at' => null,
            'details' => [],
        ];
    }

    public function seen(): static
    {
        return $this->state(fn () => ['seen_at' => now()]);
    }

    /**
     * An order the customer would not leave a number for. Still an order — the
     * owner just has to answer in the chat it came from.
     */
    public function chatOnly(): static
    {
        return $this->state(fn () => ['phone' => null, 'contact' => null]);
    }
}
