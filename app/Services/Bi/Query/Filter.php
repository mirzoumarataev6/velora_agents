<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;

/**
 * One WHERE clause, as a column row, an operator from the closed set, and values
 * that will be bound.
 *
 * Note what a filter does NOT carry: a SQL fragment, a column name, or a
 * boolean connective. Filters are always AND-ed. An OR between two different
 * columns is a genuinely different shape — it needs a tree, a precedence rule
 * and a UI to build one — and every filter the dashboard builder can express is
 * a conjunction. `IN` covers "this column is one of these", which is what OR is
 * actually wanted for nine times in ten.
 */
final class Filter
{
    /**
     * @param  array<int, mixed>  $values  Bound, always. Length already checked
     *                                     against the operator's arity.
     */
    public function __construct(
        public readonly BiColumn $column,
        public readonly FilterOperator $operator,
        public readonly array $values = [],
    ) {}

    /**
     * The values as they will actually be bound.
     *
     * Pattern operators are wrapped here rather than in the compiler so that both
     * engines bind the identical string — the escaping rule is a property of the
     * filter, not of the dialect, and duplicating it in two compilers is how the
     * two would eventually disagree.
     */
    public function bindings(): array
    {
        if (! $this->operator->isPattern()) {
            return array_values($this->values);
        }

        return [$this->operator->pattern((string) ($this->values[0] ?? ''))];
    }

    public function fingerprint(): string
    {
        return 'f:'.$this->column->getKey().':'.$this->operator->value
            .':'.md5(serialize(array_values($this->values)));
    }
}
