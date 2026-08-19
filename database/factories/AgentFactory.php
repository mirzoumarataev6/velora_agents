<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'description' => fake()->sentence(),
            'status' => 'active',
            'language' => 'auto',
            'tone' => 'friendly',
            'response_format' => [
                'greeting_message' => null,
                'fallback_message' => null,
                'max_length' => 600,
                'use_emojis' => true,
            ],
            'behavior' => ['on_not_found' => 'apologize'],
        ];
    }
}
