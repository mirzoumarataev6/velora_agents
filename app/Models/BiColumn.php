<?php

namespace App\Models;

use App\Policies\BiColumnPolicy;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Database\Factories\BiColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One column of one dataset — and the registry that makes the query layer safe.
 *
 * `physical_name` is the only identifier that ever reaches SQL, and it is ours:
 * `c0`, `c1`, `c2`, assigned by ordinal. Nothing from the owner's file — no
 * header, no cell, no sheet name — is ever spliced into an identifier position.
 * A query spec that names a column is validated by looking it up in THIS table
 * scoped to its dataset, so an identifier the model invented simply is not found
 * and the query is refused before it is compiled.
 *
 * `source_name` is the human half: shown in the schema editor, put in the AI
 * analyst's schema context, rendered on chart axes when no `label` overrides it.
 * It may contain anything at all — quotes, semicolons, Cyrillic, emoji — because
 * it is only ever data.
 */
#[Fillable([
    'ordinal', 'source_name', 'physical_name', 'label', 'type', 'inferred_type',
    'role', 'format', 'distinct_count', 'distinct_exact', 'blank_count',
    'max_length', 'sample_values', 'min_value', 'max_value', 'cast_failures',
    'cast_failure_samples', 'indexed', 'description',
])]
#[UsePolicy(BiColumnPolicy::class)]
class BiColumn extends Model
{
    /** @use HasFactory<BiColumnFactory> */
    use HasFactory;

    /**
     * The one shape a physical column name may have.
     *
     * Same reasoning as BiDataset::TABLE_PATTERN: it is checked on the way into
     * DDL and on the way into a SELECT, it cannot fail on owner input, and if it
     * ever fails it has caught our own bug.
     */
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

    /** The physical name for a column at this ordinal. */
    public static function physicalNameFor(int $ordinal): string
    {
        return 'c'.$ordinal;
    }

    /**
     * This column's physical name, re-checked against the one shape it may have.
     *
     * Every DDL and SELECT path calls this rather than reading the attribute, so
     * a row edited by hand in the database cannot become an injection vector.
     */
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

    /** What a chart axis or a table header shows for this column. */
    public function displayName(): string
    {
        return $this->label !== null && $this->label !== ''
            ? $this->label
            : $this->source_name;
    }

    /**
     * Whether this text column is materialized as TEXT rather than an indexable
     * VARCHAR — i.e. whether it is free prose rather than a category.
     */
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
