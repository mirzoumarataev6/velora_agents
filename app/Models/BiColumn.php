<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\BiColumnPolicy;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ordinal', 'source_name', 'physical_name', 'label', 'type', 'inferred_type',
    'role', 'format', 'distinct_count', 'distinct_exact', 'blank_count',
    'max_length', 'sample_values', 'min_value', 'max_value', 'cast_failures',
    'cast_failure_samples', 'indexed', 'description',
])]
#[UsePolicy(BiColumnPolicy::class)]
class BiColumn extends Model
{
    use HasFactory;
    use StampsTeam;

    public const NAME_PATTERN = '/^c\d+$/';

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'type' => ColumnType::class,
            'inferred_type' => ColumnType::class,
            'role' => ColumnRole::class,
            'format' => 'array',
            'distinct_count' => 'integer',
            'distinct_exact' => 'boolean',
            'blank_count' => 'integer',
            'max_length' => 'integer',
            'sample_values' => 'array',
            'cast_failures' => 'integer',
            'cast_failure_samples' => 'array',
            'indexed' => 'boolean',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(BiDataset::class, 'bi_dataset_id');
    }

    public static function physicalNameFor(int $ordinal): string
    {
        return 'c'.$ordinal;
    }

    public function safePhysicalName(): string
    {
        $name = (string) $this->physical_name;

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new BiIngestionException(
                BiIngestionException::UNSAFE_TABLE_NAME,
                "Refusing to use [{$name}] as a column identifier: a BI column is always c<ordinal>."
            );
        }

        return $name;
    }

    public function displayName(): string
    {
        return $this->label !== null && $this->label !== ''
            ? $this->label
            : $this->source_name;
    }

    public function isLongText(): bool
    {
        return $this->type === ColumnType::Text
            && (int) $this->max_length > ColumnType::indexableLength();
    }

    public function isMeasure(): bool
    {
        return $this->role === ColumnRole::Measure;
    }

    public function isTime(): bool
    {
        return $this->role === ColumnRole::Time;
    }
}
