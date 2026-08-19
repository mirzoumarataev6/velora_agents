<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'channel_id' => Channel::factory(),
            'external_user_id' => (string) fake()->numberBetween(100000, 999999),
            'external_user_name' => fake()->name(),
            'status' => 'ai',
            'last_message_at' => now(),
            'operator_read_at' => null,
        ];
    }

    public function humanTakeover(): static
    {
        return $this->state(fn () => ['status' => 'human_takeover']);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }

    /** The owner has already read everything in this thread. */
    public function read(): static
    {
        return $this->state(fn () => ['operator_read_at' => now()]);
    }

    /** A playground thread: the owner testing their own agent, no channel. */
    public function playground(): static
    {
        return $this->state(fn () => [
            'channel_id' => null,
            'external_user_id' => 'playground',
            'external_user_name' => 'Playground',
        ]);
    }
}
