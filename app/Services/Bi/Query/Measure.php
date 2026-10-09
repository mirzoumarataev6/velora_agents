<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Services\Bi\Schema\ColumnType;

/**
 * One number to compute — an aggregate, and the column it reads.
 *
 * Same rule as Dimension: it holds a `BiColumn` row rather than a name, and its
 * output alias (`m0`, `m1`) is ours. `COUNT` is the one case with no column at
 * all, which is why `$column` is nullable here and why the validator checks that
 * pairing rather than letting a null reach the compiler.
 */
final class Measure
{
    public function __construct(
        public readonly Aggregate $aggregate,
        public readonly ?BiColumn $column = null,
        public readonly int $position = 0,
    ) {}

    /** The alias this measure is selected as, and may be sorted by. */
    public function alias(): string
    {
        return 'm'.$this->position;
    }

    /**
     * The human name for a legend entry.
     *
     * Translated at render time rather than here: this is a service, and a label
     * built in a service is a label that cannot follow the locale switcher, which
     * in this product swaps text in place without a reload.
     */
    public function labelKey(): string
    {
        return 'bi.aggregates.'.$this->aggregate->value;
    }

    public function columnLabel(): ?string
    {
        return $this->column?->displayName();
    }

    /** What the computed value is, which is not always what the column holds. */
    public function resultType(): ColumnType
    {
        return $this->aggregate->resultType($this->column?->type);
    }

    public function fingerprint(): string
    {
        return 'm:'.$this->aggregate->value.':'.($this->column?->getKey() ?? '-');
    }
}
