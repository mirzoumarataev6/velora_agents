<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Dataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'original_filename' => 'catalog.xlsx',
            'row_count' => 0,
            'skipped_count' => 0,
            'skipped_reasons' => null,
            'mapping' => ['name' => 0, 'price' => 1, 'stock' => 2],
            'columns' => ['Название', 'Цена', 'Остаток'],
            'imported_at' => now(),
        ];
    }
}
