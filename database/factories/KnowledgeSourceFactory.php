<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSource>
 */
class KnowledgeSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'type' => 'text',
            'name' => fake()->sentence(3),
            'status' => 'pending',
        ];
    }
}
