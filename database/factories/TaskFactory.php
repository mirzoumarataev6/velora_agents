<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'customer_id' => null,
            'deal_id' => null,
            'title' => fake()->sentence(4),
            'due_at' => now()->addDay(),
            'done_at' => null,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => now()->subDays(2)]);
    }

    public function dueToday(): static
    {
        return $this->state(fn () => ['due_at' => now()->addHour()]);
    }

    public function done(): static
    {
        return $this->state(fn () => ['done_at' => now()]);
    }

    /** No date at all — "when there's time". */
    public function undated(): static
    {
        return $this->state(fn () => ['due_at' => null]);
    }
}
