<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'title' => fake()->words(3, true),
            'amount' => fake()->optional()->randomFloat(2, 50, 5000),
            'payment_status' => 'unpaid',
            'stage' => 'new_lead',
            'position' => 0,
            'stage_changed_at' => now(),
            'stage_set_by_owner_at' => null,
        ];
    }

    public function stage(string $stage): static
    {
        return $this->state(fn () => ['stage' => $stage, 'stage_changed_at' => now()]);
    }

    /** Moved by the owner, so the automation must leave it alone. */
    public function movedByOwner(): static
    {
        return $this->state(fn () => ['stage_set_by_owner_at' => now()]);
    }

    /** A fully finished, paid sale — only meaningful sitting in Оплата. */
    public function closed(): static
    {
        return $this->state(fn () => ['stage' => 'payment', 'closed_at' => now()]);
    }
}
