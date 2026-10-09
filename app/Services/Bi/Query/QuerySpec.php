<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

/**
 * A validated analytical query, as structure rather than SQL.
 *
 * The same boundary `ProductQuery` is for the live-database layer, and built on
 * the same principle: no component below this point ever accepts a query string,
 * and every identifier that will reach SQL has already been proven to exist in a
 * registry we control.
 *
 * WHAT THIS CONSTRUCTOR RE-CHECKS, AND WHY IT RE-CHECKS IT
 *
 * `SpecValidator` has already resolved every column by looking it up in
 * `bi_columns` scoped to this dataset, so in the normal path these assertions
 * cannot fail. They are here anyway, because the validator is not the only way
 * to build a spec — a dashboard reads one back from storage, the AI analyst
 * composes one, a test constructs one by hand — and the guarantee worth having
 * is "a QuerySpec that exists is safe", not "a QuerySpec built the usual way is
 * safe". The cost is a few integer comparisons per query.
 *
 * The check that matters most is the first one: every column must belong to THIS
 * dataset. A spec holding a `BiColumn` from another owner's dataset is the one
 * shape that could, in principle, make a compiled query name a column that is
 * not in the table it selects from — and, far worse, it would mean a request
 * body had been able to decide which rows the registry lookup consulted. It is
 * refused here, it is refused in the validator, and `TenantIsolationTest` proves
 * both.
 *
 * WHAT IT DELIBERATELY DOES NOT CHECK: whether the dataset is ready, and whether
 * its physical table exists. Those are facts about the world at execution time,
 * not properties of the query — a spec saved on a dashboard is still a valid
 * spec while its dataset is being rebuilt, and refusing to construct one would
 * make a rematerialization look like data loss. QueryRunner checks them, once,
 * at the moment they are true or false.
 */
final class QuerySpec
{
    /** @var array<int, Dimension> */
    public readonly array $dimensions;

    /** @var array<int, Measure> */
    public readonly array $measures;

    /** @var array<int, Filter> */
    public readonly array $filters;

    /** @var array<int, Sort> */
    public readonly array $sorts;

    public readonly int $limit;

    public readonly int $offset;

    /**
     * @param  array<int, Dimension>  $dimensions
     * @param  array<int, Measure>  $measures
     * @param  array<int, Filter>  $filters
     * @param  array<int, Sort>  $sorts
     */
    public function __construct(
        public readonly BiDataset $dataset,
        array $dimensions = [],
        array $measures = [],
        array $filters = [],
        array $sorts = [],
        int $limit = 100,
        int $offset = 0,
        public readonly bool $detail = false,
    ) {
        if ($detail && ($dimensions === [] || $measures !== [] || collect($dimensions)->contains(fn (Dimension $d) => $d->grain !== null))) {
            throw new BiQueryException(BiQueryException::WIDGET_SHAPE, 'A detail table selects raw columns without measures or time buckets.');
        }
        if ($dimensions === [] && $measures === []) {
            throw new BiQueryException(
                BiQueryException::EMPTY_SPEC,
                'A query must select at least one dimension or one measure.'
            );
        }

        $maxFields = self::maxFields();

        if (count($dimensions) + count($measures) > $maxFields) {
            throw new BiQueryException(
                BiQueryException::TOO_MANY_FIELDS,
                'A query may select at most '.$maxFields.' fields; this one selects '
                    .(count($dimensions) + count($measures)).'.'
            );
        }

        // Positions are assigned HERE rather than trusted from the caller, so the
        // aliases are dense and unique by construction. Two dimensions that both
        // claimed position 0 would compile to two `AS d0` columns, and the second
        // would silently overwrite the first in the result row.
        $indexed = [];
        foreach (array_values($dimensions) as $i => $dimension) {
            $this->assertOwned($dimension->column, 'dimension');

            if ($dimension->isBucketed() && ! $dimension->column->type->isTemporal()) {
                throw new BiQueryException(
                    BiQueryException::NOT_TEMPORAL,
                    "Column [{$dimension->column->physical_name}] is {$dimension->column->type->value}, "
                        .'so it cannot be bucketed by '.$dimension->grain->value.'.'
                );
            }

            $indexed[] = new Dimension($dimension->column, $dimension->grain, $i);
        }

        $this->dimensions = $indexed;

        $indexedMeasures = [];
        foreach (array_values($measures) as $i => $measure) {
            if ($measure->aggregate->needsColumn() && $measure->column === null) {
                throw new BiQueryException(
                    BiQueryException::UNKNOWN_COLUMN,
                    $measure->aggregate->value.' needs a column to read.'
                );
            }

            if ($measure->column !== null) {
                $this->assertOwned($measure->column, 'measure');

                if (! $measure->aggregate->accepts($measure->column->type)) {
                    throw new BiQueryException(
                        BiQueryException::NOT_NUMERIC,
                        $measure->aggregate->value." cannot be applied to [{$measure->column->physical_name}], "
                            ."which is {$measure->column->type->value}."
                    );
                }
            }

            $indexedMeasures[] = new Measure($measure->aggregate, $measure->column, $i);
        }

        $this->measures = $indexedMeasures;

        foreach ($filters as $filter) {
            $this->assertOwned($filter->column, 'filter');
        }

        $this->filters = array_values($filters);

        // A sort may only name something this query actually produces. See Sort's
        // docblock for why that restriction is a feature rather than a limitation.
        $selectable = [];
        foreach ($this->dimensions as $dimension) {
            $selectable[$dimension->alias()] = true;
        }
        foreach ($this->measures as $measure) {
            $selectable[$measure->alias()] = true;
        }

        foreach ($sorts as $sort) {
            if (! isset($selectable[$sort->alias])) {
                throw new BiQueryException(
                    BiQueryException::UNSORTABLE,
                    "Cannot sort by [{$sort->alias}]: this query does not select it."
                );
            }
        }

        $this->sorts = array_values($sorts);

        // Int-cast and range-checked, because these two are the ONLY values in the
        // whole compiled statement that are interpolated rather than bound —
        // LIMIT and OFFSET cannot be placeholders in every engine/driver pairing
        // this product has to work with. Clamped rather than refused: a UI asking
        // for 10,000 rows wants as many as it can have, and a hard error there
        // would be a worse answer than 5,000 rows.
        $this->limit = max(1, min($limit, self::maxRows()));
        $this->offset = max(0, min($offset, self::maxOffset()));
    }

    /**
     * The tenant re-check, in the one place every field passes through.
     *
     * Compares the column's `bi_dataset_id` to this spec's dataset key. It is not
     * enough for the column to belong to the same OWNER: the compiled query reads
     * exactly one physical table, and a column from a sibling dataset would name
     * an identifier that table does not have.
     */
    private function assertOwned(BiColumn $column, string $role): void
    {
        if ((int) $column->bi_dataset_id !== (int) $this->dataset->getKey()) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_COLUMN,
                "A {$role} names column [{$column->getKey()}], which belongs to dataset "
                    ."[{$column->bi_dataset_id}] rather than [{$this->dataset->getKey()}]."
            );
        }
    }

    /**
     * Whether this query groups at all.
     *
     * A spec with measures and no dimensions is a single-row total ("what is the
     * revenue"), which needs aggregates but no GROUP BY. A spec with dimensions
     * and no measures is a distinct-values list, which needs a GROUP BY and no
     * aggregates. Both are real things a dashboard asks for.
     */
    public function isGrouped(): bool
    {
        return $this->dimensions !== [] && $this->measures !== [];
    }

    /** Whether this query returns at most one row, whatever the data. */
    public function isScalar(): bool
    {
        return $this->dimensions === [] && $this->measures !== [];
    }

    /**
     * A stable identity for this query, for the result cache.
     *
     * Includes the dataset's `version`, which the materializer bumps on every
     * rebuild. That is what makes correcting a column's type invalidate every
     * cached chart over it without anything having to go and find them — the old
     * entries are simply unreachable and expire on their own TTL.
     *
     * Column IDS rather than names: renaming a header must NOT invalidate a
     * cached result, because the numbers did not change.
     */
    public function fingerprint(): string
    {
        $parts = ['ds:'.$this->dataset->getKey(), 'v:'.(int) $this->dataset->version];
        $parts[] = 'detail:'.(int) $this->detail;

        foreach ($this->dimensions as $dimension) {
            $parts[] = $dimension->fingerprint();
        }
        foreach ($this->measures as $measure) {
            $parts[] = $measure->fingerprint();
        }

        // Filters are SORTED before hashing: `region = X AND month = Y` and
        // `month = Y AND region = X` are the same question and should be one
        // cache entry, and the compiler AND-s them in whatever order they arrive.
        $filters = array_map(fn (Filter $f) => $f->fingerprint(), $this->filters);
        sort($filters);
        $parts = array_merge($parts, $filters);

        // Sorts are NOT sorted — their order is their meaning.
        foreach ($this->sorts as $sort) {
            $parts[] = $sort->fingerprint();
        }

        $parts[] = 'l:'.$this->limit;
        $parts[] = 'o:'.$this->offset;

        return sha1(implode('|', $parts));
    }

    public static function maxRows(): int
    {
        return max(1, (int) config('agent.bi.query.max_rows', 5000));
    }

    /**
     * The furthest a caller may page.
     *
     * A ceiling exists because `OFFSET 900000` makes the engine walk and discard
     * 900,000 rows — the cost of a deep page is paid by the server, not the
     * caller, and nobody scrolls to row 900,000 of a chart. Tied to `max_rows`
     * rather than configured separately: a hundred pages of the largest result
     * the product will return is already past any real use.
     */
    public static function maxOffset(): int
    {
        return self::maxRows() * 100;
    }

    public static function maxFields(): int
    {
        return max(2, (int) config('agent.bi.query.max_fields', 24));
    }
}
