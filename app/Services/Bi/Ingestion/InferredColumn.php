<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;

/**
 * What inference concluded about one column. A plain carrier between
 * SchemaInferrer and the `bi_columns` rows the normalizer writes.
 */
final class InferredColumn
{
    /**
     * @param  array<string, mixed>  $format
     * @param  array<int, string>  $sampleValues
     */
    public function __construct(
        public readonly int $ordinal,
        public readonly string $sourceName,
        public readonly string $physicalName,
        public readonly ColumnType $type,
        public readonly ColumnRole $role,
        public readonly array $format,
        public readonly int $distinctCount,
        public readonly bool $distinctExact,
        public readonly int $blankCount,
        /**
         * Values seen that were neither blank nor unreadable. NOT persisted — it is
         * the denominator the index decision needs (a column where every value is
         * distinct is an id, not a dimension) and nothing downstream of ingest asks
         * about it again.
         */
        public readonly int $nonBlankCount,
        public readonly int $maxLength,
        public readonly array $sampleValues,
        public readonly ?string $minValue,
        public readonly ?string $maxValue,
        public readonly bool $indexed,
    ) {}

    /**
     * Whether this text column is too long to live in an indexable VARCHAR and
     * must be materialized as TEXT.
     */
    public function isLongText(): bool
    {
        return $this->type === ColumnType::Text
            && $this->maxLength > ColumnType::indexableLength();
    }

    /** @return array<string, mixed> The `bi_columns` attributes for this column. */
    public function toAttributes(): array
    {
        return [
            'ordinal' => $this->ordinal,
            'source_name' => $this->sourceName,
            'physical_name' => $this->physicalName,
            'type' => $this->type,
            'inferred_type' => $this->type,
            'role' => $this->role,
            'format' => $this->format === [] ? null : $this->format,
            'distinct_count' => $this->distinctCount,
            'distinct_exact' => $this->distinctExact,
            'blank_count' => $this->blankCount,
            'max_length' => $this->maxLength,
            'sample_values' => $this->sampleValues,
            'min_value' => $this->minValue,
            'max_value' => $this->maxValue,
            'indexed' => $this->indexed,
        ];
    }
}
