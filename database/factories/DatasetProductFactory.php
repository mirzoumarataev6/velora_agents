<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\DatasetProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatasetProduct>
 */
class DatasetProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dataset_id' => Dataset::factory(),
            // Defaults to the dataset's own agent, so a product created through
            // the factory can never end up scoped to a different tenant than the
            // catalog it belongs to.
            'agent_id' => fn (array $attributes) => Dataset::find($attributes['dataset_id'])?->agent_id
                ?? Dataset::factory()->create()->agent_id,
            'name' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 10, 5000),
            'stock' => fake()->numberBetween(0, 50),
            'category' => fake()->word(),
            'description' => fake()->sentence(),
            'sku' => strtoupper(fake()->bothify('??-####')),
            'url' => null,
            'extra' => null,
            'position' => 0,
            // Filled in properly by the model's saving hook; present here so a
            // factory used with ->make() still has the columns.
            'search_text' => '',
            'category_key' => null,
        ];
    }
}
