<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Services\Bi\Schema\ColumnType;

/**
 * One thing to split the numbers BY — a column, optionally bucketed.
 *
 * Holds the `BiColumn` ROW, never a column name. That is the whole safety story
 * of this layer in one sentence: by the time a dimension exists, the identifier
 * it will put in the SQL has already been looked up in `bi_columns` scoped to
 * the dataset, so an invented name cannot be represented here at all — there is
 * no constructor that takes a string.
 *
 * The output alias is OURS (`d0`, `d1`), not the owner's header. A column called
 * `Выручка, сом` or `total"` is ordinary data in this product, and the moment a
 * header reaches an identifier position it stops being data. The owner's text
 * travels alongside as `label`, for the chart to render.
 */
final class Dimension
{
    public function __construct(
        public readonly BiColumn $column,
        public readonly ?TimeGrain $grain = null,
        public readonly int $position = 0,
    ) {}

    /** The alias this dimension is selected as, and grouped and sorted by. */
    public function alias(): string
    {
        return 'd'.$this->position;
    }

    /** The human name for an axis or a table header. */
    public function label(): string
    {
        return $this->column->displayName();
    }

    public function isBucketed(): bool
    {
        return $this->grain !== null;
    }

    /**
     * The type the SELECTED value has.
     *
     * A bucketed date is text, not a date: `2026-Q1` is not a date in any engine,
     * and even `2026-03-01` arrives as a string because it was built by a string
     * function. Saying so here keeps the UI from trying to re-parse a quarter.
     */
    public function resultType(): ColumnType
    {
        return $this->isBucketed() ? ColumnType::Text : $this->column->type;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** The part of the cache fingerprint this dimension contributes. */
    public function fingerprint(): string
    {
        return 'd:'.$this->column->getKey().':'.($this->grain?->value ?? '-');
    }
}
