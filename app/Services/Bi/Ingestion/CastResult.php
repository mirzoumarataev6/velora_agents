<?php

namespace App\Services\Bi\Ingestion;

/**
 * The outcome of casting one cell: a value, a blank, or a refusal.
 *
 * Three states rather than "value or null", because the difference between the
 * last two is the difference between a truthful dashboard and a quiet lie. An
 * empty cell and a cell containing "уточнить" both end up as NULL in the table,
 * but only the second is a value the owner wrote that we could not keep — and
 * only the second should be counted and shown back to them. Collapsing them
 * would make a column that rejected 8% of its rows indistinguishable from one
 * that was simply 8% empty.
 */
final class CastResult
{
    private function __construct(
        public readonly mixed $value,
        public readonly bool $failed,
        public readonly bool $blank,
    ) {}

    /** A value that cast cleanly. */
    public static function ok(mixed $value): self
    {
        return new self($value, failed: false, blank: false);
    }

    /** The cell was empty, or held a recognized placeholder for empty. */
    public static function blank(): self
    {
        return new self(null, failed: false, blank: true);
    }

    /** The cell held something real that does not fit this column's type. */
    public static function failed(): self
    {
        return new self(null, failed: true, blank: false);
    }

    /** Whether this result contributes a usable value to the column. */
    public function isUsable(): bool
    {
        return ! $this->failed && ! $this->blank;
    }
}
