<?php

namespace Database\Factories;

use App\Models\CrmStage;
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

    /**
     * Keeps crm_stage_id/stage_role in step with whatever `stage` a test
     * fixture asks for, against that owner's (lazily-seeded, default)
     * pipeline — the same invariant DealBoard::move() keeps at runtime.
     * Without this, every existing test built around the literal stage
     * string would silently produce a deal with no role at all, and every
     * role-keyed feature (payment-integrity guard, revenue, the trend
     * report) would read it as not-WON/not-LOST regardless of `stage`.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Deal $deal) {
            $user = User::find($deal->user_id);

            if ($user === null) {
                return;
            }

            $stage = CrmStage::defaultsFor($user)->firstWhere('name', $deal->stage);
            $deal->crm_stage_id = $stage?->id;
            $deal->stage_role = $stage?->role;
        });
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
