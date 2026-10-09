<?php

namespace Database\Factories;

use App\Models\BiDataset;
use App\Models\BiSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiDataset>
 */
class BiDatasetFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Deliberately NOT defaulted to its source's owner here: a test that
            // wants a cross-tenant mismatch must be able to build one, and
            // `forOwner()` below is the one-liner for the normal case.
            'user_id' => fn (array $attributes) => BiSource::find($attributes['bi_source_id'])?->user_id,
            'bi_source_id' => BiSource::factory(),
            'name' => 'Sales',
            'sheet_name' => null,
            'physical_table' => null,
            'external_table' => null,
            'row_count' => 0,
            'column_count' => 0,
            'version' => 0,
            'status' => BiDataset::STATUS_READY,
            'skipped_rows' => 0,
            'truncated' => false,
            'shard_count' => 0,
        ];
    }

    /**
     * Fill in `physical_table` and `shard_directory`, which in production are
     * written only after the row exists because both are derived from its key.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (BiDataset $dataset) {
            if ($dataset->physical_table === null) {
                $dataset->forceFill([
                    'physical_table' => $dataset->physicalTable(),
                    'shard_directory' => "bi/{$dataset->user_id}/datasets/{$dataset->getKey()}",
                ])->saveQuietly();
            }
        });
    }
}
