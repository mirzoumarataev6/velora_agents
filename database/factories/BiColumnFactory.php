<?php

namespace Database\Factories;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiColumn>
 */
class BiColumnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => fn (array $attributes) => BiDataset::find($attributes['bi_dataset_id'])?->user_id,
            'bi_dataset_id' => BiDataset::factory(),
            'ordinal' => 0,
            'source_name' => 'Выручка',
            'physical_name' => 'c0',
            'label' => null,
            'type' => ColumnType::Decimal,
            'inferred_type' => ColumnType::Decimal,
            'role' => ColumnRole::Measure,
            'format' => null,
            'distinct_count' => 100,
            'distinct_exact' => true,
            'blank_count' => 0,
            'max_length' => 8,
            'sample_values' => ['1500.00', '2400.50'],
            'min_value' => '0',
            'max_value' => '9999',
            'cast_failures' => 0,
            'cast_failure_samples' => null,
            'indexed' => false,
            'description' => null,
        ];
    }

    /** A column at a given ordinal, with its physical name kept consistent. */
    public function at(int $ordinal): static
    {
        return $this->state(fn () => [
            'ordinal' => $ordinal,
            'physical_name' => BiColumn::physicalNameFor($ordinal),
        ]);
    }

    public function type(ColumnType $type, ?ColumnRole $role = null): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'inferred_type' => $type,
            'role' => $role ?? $type->defaultRole(),
        ]);
    }
}
