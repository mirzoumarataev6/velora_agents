<?php

namespace App\Services\Bi\Query;

/**
 * One ORDER BY term, named by the OUTPUT ALIAS rather than by a column.
 *
 * This is the deliberate part. Sorting is expressed as "sort by `m0`", one of
 * the aliases this query itself produces, and the validator refuses an alias the
 * spec does not select. Three things follow:
 *
 *  - Sorting by an aggregate — the single most common thing a dashboard wants,
 *    "top 10 regions by revenue" — needs no special case, because the aggregate
 *    already has a name.
 *  - A sort can never widen what the query touches. Ordering by a column that is
 *    neither grouped nor aggregated is not merely refused here, it is
 *    unrepresentable, so the engine is never handed a query whose answer depends
 *    on which row it happened to pick.
 *  - The alias is from a closed set we generated (`d0`, `m1`), so the one
 *    identifier an ORDER BY contributes cannot come from a request at all.
 */
final class Sort
{
    public function __construct(
        public readonly string $alias,
        public readonly SortDirection $direction = SortDirection::Asc,
    ) {}

    public function fingerprint(): string
    {
        return 's:'.$this->alias.':'.$this->direction->value;
    }
}
