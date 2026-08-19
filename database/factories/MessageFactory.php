<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => 'user',
            'content' => fake()->sentence(),
            'external_message_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'meta' => null,
        ];
    }

    public function fromAgent(): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'external_message_id' => null,
            'meta' => ['retrieved_chunks' => [], 'failed' => false],
        ]);
    }

    public function fromOperator(): static
    {
        return $this->state(fn () => ['role' => 'operator', 'external_message_id' => null]);
    }
}
