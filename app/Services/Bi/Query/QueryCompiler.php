<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

/**
 * Compiles a QuerySpec into one SELECT with bound values.
 *
 * Public, and deliberately so — the same decision as the live-database layer,
 * and for the same reason: THE SHAPE OF THE SQL IS THE SECURITY PROPERTY, so it
 * has to be assertable as a string in a unit test without a server anywhere near
 * it. `tests/Unit/Bi/QueryCompilerTest.php` reads the output of both subclasses
 * directly. A compiler that could only be observed through a live connection
 * would be a compiler whose guarantees were argued rather than tested.
 *
 * The four invariants, each enforced below and each asserted in that test:
 *
 *  1. Every identifier is quoted, and every identifier comes from either a
 *     `BiColumn` row (re-checked here through `safePhysicalName()`) or from the
 *     dataset's own primary key (through `physicalTable()`). Nothing from a
 *     request reaches an identifier position — there is no code path that could
 *     carry it there, because QuerySpec cannot hold a string column name.
 *  2. Every value is a `?` placeholder. The two exceptions are LIMIT and OFFSET,
 *     which several driver/engine pairings refuse to bind; both are int-cast and
 *     clamped by QuerySpec before they arrive.
 *  3. The FROM clause is the tenant boundary. It is `$spec->dataset->physicalTable()`
 *     and nothing else — no filter, no field and no request parameter
 *     participates in choosing it. See BiDataset's docblock.
 *  4. `assertSelectOnly()` re-reads the finished string as a last line of
 *     defence, with quoted identifiers stripped first.
 *
 * WHY TWO DIALECTS. Production is MySQL; the test suite is SQLite `:memory:`.
 * Having the suite test a MySQL-only compiler against nothing, or test a
 * generic one that production does not use, would both be theatre. So both are
 * real, both are tested, and the places where the engines genuinely differ —
 * date bucketing, LIKE escaping, statement timeouts — are isolated to the
 * abstract methods at the bottom of this file and documented where they live.
 */
abstract class QueryCompiler
{
    /**
     * Characters an identifier may never contain, whatever the engine.
     *
     * Belt and braces over `/^c\d+$/` and `/^bi_ds_\d+$/`, which already exclude
     * every one of them. Same reasoning as `AbstractPdoDriver`: a name that could
     * only have come from tampering never reaches the server at all.
     */
    protected const FORBIDDEN_IDENTIFIER_PATTERN = '/[\x00-\x1F\x7F`"\'\\\;]/u';

    /** The engine key this compiler speaks, matching Laravel's driver names. */
    abstract public function engine(): string;

    /** The character this engine quotes identifiers with. */
    abstract protected function quoteChar(): string;

    /**
     * The bucketing expression for a date column at a given grain.
     *
     * The one place the two dialects do real work differently, and the one place
     * they are required to produce byte-identical OUTPUT. See TimeGrain.
     */
    abstract protected function timeBucket(string $quotedColumn, TimeGrain $grain): string;

    /**
     * Anything that goes between `SELECT` and the first column — the timeout
     * hint, where the engine has one.
     */
    abstract protected function selectPrefix(int $timeoutMs): string;

    public function compile(QuerySpec $spec): CompiledQuery
    {
        $bindings = [];

        $select = [];
        $groupBy = [];

        foreach ($spec->dimensions as $dimension) {
            $expression = $this->dimensionExpression($dimension);
            $alias = $this->quote($dimension->alias());

            $select[] = $expression.' AS '.$alias;

            // GROUP BY names the ALIAS, not the expression. Both engines accept
            // it, and it removes a whole class of bug by construction: a grouped
            // query whose GROUP BY has drifted from its SELECT cannot be written,
            // because there is only one expression and the group refers to it.
            // Repeating a DATE_FORMAT(...) in both clauses is how that drift
            // happens, and MySQL would accept the result rather than complain.
            $groupBy[] = $alias;
        }

        foreach ($spec->measures as $measure) {
            $select[] = $this->measureExpression($measure).' AS '.$this->quote($measure->alias());
        }

        $sql = 'SELECT '.$this->selectPrefix($this->timeoutMs()).implode(', ', $select)
            .' FROM '.$this->quoteTable($spec->dataset);

        [$where, $whereBindings] = $this->whereClause($spec);

        if ($where !== '') {
            $sql .= ' WHERE '.$where;
            $bindings = array_merge($bindings, $whereBindings);
        }

        // A spec with dimensions but no measures still groups: it is a
        // distinct-values list, and GROUP BY is how you ask for one without
        // DISTINCT's different interaction with ORDER BY across engines.
        if ($groupBy !== [] && ! $spec->detail) {
            $sql .= ' GROUP BY '.implode(', ', $groupBy);
        }

        if ($spec->sorts !== []) {
            $terms = [];

            foreach ($spec->sorts as $sort) {
                $terms[] = $this->orderTerm($this->quote($sort->alias), $sort->direction);
            }

            $sql .= ' ORDER BY '.implode(', ', $terms);
        } elseif ($spec->detail) {
            // File order is a stable pagination key. Every analytical table has
            // this internal integer column; it is never supplied by a caller.
            $sql .= ' ORDER BY '.$this->detailOrder($spec);
        }

        // The probe row: one more than asked for, so the caller learns whether a
        // next page exists without a second COUNT. See CompiledQuery::hasMore().
        $fetchLimit = $spec->limit + 1;

        $sql .= ' LIMIT '.$fetchLimit;

        if ($spec->offset > 0) {
            $sql .= ' OFFSET '.$spec->offset;
        }

        $this->assertSelectOnly($sql);

        return new CompiledQuery($sql, $bindings, $spec->limit, $fetchLimit);
    }

    protected function dimensionExpression(Dimension $dimension): string
    {
        $quoted = $this->column($dimension->column);

        return $dimension->isBucketed()
            ? $this->timeBucket($quoted, $dimension->grain)
            : $quoted;
    }

    protected function measureExpression(Measure $measure): string
    {
        if ($measure->aggregate === Aggregate::Count) {
            // COUNT(*), not COUNT(a column): every materialized column is
            // nullable, so counting one would silently skip the rows where it is
            // blank — and "how many orders" must not depend on which column the
            // caller happened to point at. See Aggregate::needsColumn().
            return 'COUNT(*)';
        }

        $quoted = $this->column($measure->column);

        return match ($measure->aggregate) {
            Aggregate::CountDistinct => 'COUNT(DISTINCT '.$quoted.')',
            Aggregate::Sum => 'SUM('.$quoted.')',
            Aggregate::Avg => 'AVG('.$quoted.')',
            Aggregate::Min => 'MIN('.$quoted.')',
            Aggregate::Max => 'MAX('.$quoted.')',
            // Unreachable: Aggregate is a closed set and Count returned above.
            // Re-checked rather than defaulted, so adding a case to the enum
            // without teaching the compiler is an immediate, loud failure.
            default => throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                'Compiler has no expression for aggregate ['.$measure->aggregate->value.'].'
            ),
        };
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function whereClause(QuerySpec $spec): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($spec->filters as $filter) {
            [$sql, $values] = $this->filterClause($filter);

            $clauses[] = $sql;
            $bindings = array_merge($bindings, $values);
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function filterClause(Filter $filter): array
    {
        $column = $this->column($filter->column);
        $values = $filter->bindings();

        return match ($filter->operator) {
            FilterOperator::Equals => [$column.' = ?', $values],

            // NULL-aware on purpose. `c <> 'Душанбе'` excludes blanks in both
            // engines, so "region is not Dushanbe" would silently drop every row
            // whose region was never filled in — which is not what the owner
            // asked and, worse, is invisible in the result. A blank cell is
            // ordinary data in this module, so it is not Dushanbe, so it stays.
            FilterOperator::NotEquals => ['('.$column.' IS NULL OR '.$column.' <> ?)', $values],

            FilterOperator::GreaterThan => [$column.' > ?', $values],
            FilterOperator::GreaterOrEqual => [$column.' >= ?', $values],
            FilterOperator::LessThan => [$column.' < ?', $values],
            FilterOperator::LessOrEqual => [$column.' <= ?', $values],

            FilterOperator::In => [
                $column.' IN ('.implode(', ', array_fill(0, count($values), '?')).')',
                $values,
            ],

            // Same NULL reasoning as NotEquals.
            FilterOperator::NotIn => [
                '('.$column.' IS NULL OR '.$column.' NOT IN ('
                    .implode(', ', array_fill(0, count($values), '?')).'))',
                $values,
            ],

            FilterOperator::Contains,
            FilterOperator::StartsWith,
            FilterOperator::EndsWith => [
                $this->likeClause($column),
                [mb_strtolower((string) ($values[0] ?? ''))],
            ],

            FilterOperator::Between => [$column.' BETWEEN ? AND ?', $values],

            // Blank means NULL for every type, and ALSO the empty string for
            // text: the normalizer stores an empty cell as NULL, but a cell
            // holding a single space survives trimming as ''. Both are "the
            // owner left it blank", and an owner filtering for blanks who got
            // only half of them would reasonably call that a bug.
            FilterOperator::IsBlank => $filter->column->type->isNumeric() || $filter->column->type->isTemporal()
                ? [$column.' IS NULL', []]
                : ['('.$column.' IS NULL OR '.$column.' = ?)', ['']],

            FilterOperator::IsNotBlank => $filter->column->type->isNumeric() || $filter->column->type->isTemporal()
                ? [$column.' IS NOT NULL', []]
                : ['('.$column.' IS NOT NULL AND '.$column.' <> ?)', ['']],
        };
    }

    /**
     * A case-normalized LIKE, spelled the same way on both engines.
     *
     * Both sides are lowered rather than relying on collation, because the two
     * engines disagree by default: MySQL's `utf8mb4_unicode_ci` compares
     * case-insensitively and SQLite's `=` does not, so the identical filter would
     * return different rows — and the suite's whole value rests on it not doing
     * that.
     *
     * HONEST LIMITATION, because it is better stated than discovered: SQLite's
     * `LOWER()` only folds ASCII, so under SQLite a search for `душанбе` will not
     * match `Душанбе`. Production is MySQL, where ICU case folding handles
     * Cyrillic correctly, so this affects local dev and the test suite only — and
     * the suite's text fixtures are chosen to avoid depending on it. Making it
     * genuinely identical would mean shipping a custom SQLite collation, which is
     * a lot of surface area for a difference no owner can observe.
     *
     * `ESCAPE '\'` is stated explicitly: MySQL defaults to backslash, SQLite
     * defaults to no escape character at all, and FilterOperator::pattern() has
     * already escaped the caller's own `%` and `_` on that assumption.
     */
    protected function likeClause(string $quotedColumn): string
    {
        return 'LOWER('.$quotedColumn.") LIKE ? ESCAPE '\\'";
    }

    /**
     * How one ORDER BY term is spelled: nulls last, in both directions, on both
     * engines.
     *
     * Shared rather than per-dialect, and that is the interesting part. Left to
     * their defaults the two engines agree with each other and disagree with what
     * a chart wants: both sort NULLs FIRST on ASC. So a "cheapest region" sort
     * would lead with the region that has no value at all — and worse, the blank
     * bucket's position would be the one thing a reader's eye goes to first.
     *
     * `NULLS LAST` is not an option: MySQL has no such clause, and SQLite only
     * gained it in 3.30. The `IS NULL` test is understood by both, on every
     * version, and emits character-for-character the same text — which is what
     * lets the compiler test diff the two dialects' output directly instead of
     * maintaining two expected strings that drift apart.
     */
    protected function orderTerm(string $quotedAlias, SortDirection $direction): string
    {
        return $quotedAlias.' IS NULL, '.$quotedAlias.' '.$direction->sql();
    }

    /**
     * The physical table, re-checked on the way into the FROM clause.
     *
     * `physicalTable()` already asserts the shape; calling it here rather than
     * reading `physical_table` means a row edited by hand in the database cannot
     * redirect a query to another table.
     */
    protected function table(BiDataset $dataset): string
    {
        return BiDataset::assertSafeTableName($dataset->physicalTable());
    }

    protected function quoteTable(BiDataset $dataset): string
    {
        return $this->quote($this->table($dataset));
    }

    protected function column(BiColumn $column): string
    {
        return $this->quote($column->safePhysicalName());
    }

    protected function detailOrder(QuerySpec $spec): string
    {
        return $this->quote(BiDataset::ROW_KEY).' ASC';
    }

    /**
     * Quote an identifier, refusing outright anything that could not have come
     * from our own naming.
     */
    protected function quote(string $identifier): string
    {
        if ($identifier === '' || preg_match(self::FORBIDDEN_IDENTIFIER_PATTERN, $identifier) === 1) {
            throw new BiQueryException(
                BiQueryException::UNSAFE_SQL,
                'Refusing to quote an identifier containing forbidden characters.'
            );
        }

        $char = $this->quoteChar();

        return $char.str_replace($char, $char.$char, $identifier).$char;
    }

    /**
     * The last line of defence: re-read the finished string.
     *
     * Unreachable by construction — every identifier came from `/^c\d+$/`,
     * `/^bi_ds_\d+$/` or our own alias generator, and every value is a `?`. It
     * runs anyway, on every query, because the cost is one pass over a short
     * string and the thing it would catch is a bug in the compiler itself.
     *
     * Scanning is done with quoted identifiers stripped, matching
     * `AbstractPdoDriver::assertSelectOnly()`. Here that is defensive rather than
     * necessary — a BI identifier can never contain a keyword — but the two
     * guards are deliberately the same code shape, so that reading one teaches
     * you the other.
     */
    protected function assertSelectOnly(string $sql): void
    {
        if (! preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new BiQueryException(
                BiQueryException::UNSAFE_SQL,
                'Refusing to run a statement that is not a SELECT.'
            );
        }

        $char = preg_quote($this->quoteChar(), '/');
        $bare = preg_replace('/'.$char.'(?:[^'.$char.']|'.$char.$char.')*'.$char.'/u', '?', $sql) ?? $sql;

        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|CALL|MERGE|REPLACE|ATTACH|PRAGMA|INTO\s+OUTFILE)\b/i', $bare) === 1) {
            throw new BiQueryException(
                BiQueryException::UNSAFE_SQL,
                'Refusing to run a statement containing a write keyword.'
            );
        }

        if (str_contains($sql, ';')) {
            throw new BiQueryException(
                BiQueryException::UNSAFE_SQL,
                'Refusing to run a statement containing a statement separator.'
            );
        }
    }

    /** The statement ceiling, in milliseconds. */
    protected function timeoutMs(): int
    {
        return max(1, (int) config('agent.bi.query.timeout_seconds', 30)) * 1000;
    }

    /**
     * The physical name of a column, re-checked. Kept as a named helper so the
     * guard is visible at every call site rather than buried in a chain.
     */
    protected function physical(BiColumn $column): string
    {
        return $column->safePhysicalName();
    }
}
