<?php

namespace Database\Factories;

use App\Models\BiSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiSource>
 */
class BiSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => BiSource::KIND_FILE,
            'name' => 'sales.csv',
            'original_filename' => 'sales.csv',
            'disk_path' => null,
            'bytes' => 1024,
            'mime_type' => 'text/csv',
            'extension' => 'csv',
            'bi_connection_id' => null,
            'status' => BiSource::STATUS_READY,
            'error_code' => null,
            'error_detail' => null,
            'normalized_at' => now(),
        ];
    }

    /** Freshly uploaded and not yet picked up by the queue. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => BiSource::STATUS_PENDING,
            'normalized_at' => null,
        ]);
    }

    public function failed(string $code = 'corrupt_file'): static
    {
        return $this->state(fn () => [
            'status' => BiSource::STATUS_FAILED,
            'error_code' => $code,
            'error_detail' => 'The file could not be opened as a spreadsheet.',
            'normalized_at' => null,
        ]);
    }
}
