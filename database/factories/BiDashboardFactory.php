<?php

namespace Database\Factories;

use App\Models\BiDashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiDashboard>
 */
class BiDashboardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Продажи',
            'description' => null,
            'filters' => null,
            'is_default' => false,
            'position' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /**
     * Board-level filters, in the keyed-by-dataset shape the column stores.
     *
     * @param  array<int, array<string, mixed>>  $filters
     */
    public function filtering(int $datasetId, array $filters): static
    {
        return $this->state(fn () => ['filters' => [(string) $datasetId => $filters]]);
    }
}
