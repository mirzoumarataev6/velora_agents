<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

abstract class QueryCompiler
{
    protected const FORBIDDEN_IDENTIFIER_PATTERN = '/[\x00-\x1F\x7F`"\'\\\;]/u';

    abstract public function engine(): string;

    abstract protected function quoteChar(): string;

    abstract protected function timeBucket(string $quotedColumn, TimeGrain $grain): string;

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

            $sql .= ' ORDER BY '.$this->detailOrder($spec);
        }

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

            return 'COUNT(*)';
        }

        $quoted = $this->column($measure->column);

        return match ($measure->aggregate) {
            Aggregate::CountDistinct => 'COUNT(DISTINCT '.$quoted.')',
            Aggregate::Sum => 'SUM('.$quoted.')',
            Aggregate::Avg => 'AVG('.$quoted.')',
            Aggregate::Min => 'MIN('.$quoted.')',
            Aggregate::Max => 'MAX('.$quoted.')',

            default => throw new BiQueryException(
                BiQueryException::UNKNOWN_OPERATION,
                'Compiler has no expression for aggregate ['.$measure->aggregate->value.'].'
            ),
        };
    }

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

    protected function filterClause(Filter $filter): array
    {
        $column = $this->column($filter->column);
        $values = $filter->bindings();

        return match ($filter->operator) {
            FilterOperator::Equals => [$column.' = ?', $values],

            FilterOperator::NotEquals => ['('.$column.' IS NULL OR '.$column.' <> ?)', $values],

            FilterOperator::GreaterThan => [$column.' > ?', $values],
            FilterOperator::GreaterOrEqual => [$column.' >= ?', $values],
            FilterOperator::LessThan => [$column.' < ?', $values],
            FilterOperator::LessOrEqual => [$column.' <= ?', $values],

            FilterOperator::In => [
                $column.' IN ('.implode(', ', array_fill(0, count($values), '?')).')',
                $values,
            ],

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

            FilterOperator::IsBlank => $filter->column->type->isNumeric() || $filter->column->type->isTemporal()
                ? [$column.' IS NULL', []]
                : ['('.$column.' IS NULL OR '.$column.' = ?)', ['']],

            FilterOperator::IsNotBlank => $filter->column->type->isNumeric() || $filter->column->type->isTemporal()
                ? [$column.' IS NOT NULL', []]
                : ['('.$column.' IS NOT NULL AND '.$column.' <> ?)', ['']],
        };
    }

    protected function likeClause(string $quotedColumn): string
    {
        return 'LOWER('.$quotedColumn.") LIKE ? ESCAPE '\\'";
    }

    protected function orderTerm(string $quotedAlias, SortDirection $direction): string
    {
        return $quotedAlias.' IS NULL, '.$quotedAlias.' '.$direction->sql();
    }

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

    protected function timeoutMs(): int
    {
        return max(1, (int) config('agent.bi.query.timeout_seconds', 30)) * 1000;
    }

    protected function physical(BiColumn $column): string
    {
        return $column->safePhysicalName();
    }
}
