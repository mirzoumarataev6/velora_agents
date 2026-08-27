<?php

namespace App\Services\Database\Discovery;

use InvalidArgumentException;

/**
 * The set of joins one product search may compose, and the rules they obey.
 *
 * The single-table feature had a simple safety story: every identifier came from
 * a stored mapping that had been checked against a stored schema snapshot. Joins
 * widen what a query can name — another table, its columns, and the pair of
 * columns the two are matched on — so the same closed-set rule has to cover all
 * of them. assertValid() is where that happens, and ProductQuery calls it before
 * a driver ever sees the plan.
 *
 * The rules, all enforced below:
 *
 *  1. Every table and every column named here exists in the discovered schema.
 *  2. Aliases are ours (`j0`, `j1`), unique, and match a fixed pattern.
 *  3. A join attaches to the product table or to an EARLIER join — never to a
 *     later one, so the chain cannot loop.
 *  4. `agent.database.discovery.max_join_depth` bounds BOTH how many joins a
 *     plan may hold and how long a chain of them may get. There is no arbitrary
 *     walk of the customer's schema graph.
 *  5. The joined side is UNIQUE, or the join is AGGREGATED. Without one of the
 *     two a product with three stock rows comes back three times, and a 20-row
 *     cap returns six products. An aggregated join replaces the engine's missing
 *     guarantee with one of its own: it reads a `GROUP BY <join key>` derived
 *     table, which has exactly one row per product because that is what grouping
 *     means. Rule 6 is what keeps that true.
 *  6. An aggregated join hangs off the PRODUCT TABLE, nothing hangs off IT, and
 *     it folds only distinct NUMERIC columns with a function from
 *     Join::AGGREGATES. Summing a name is meaningless; summing a price across
 *     warehouses is worse than meaningless, so the planner only ever aggregates
 *     stock — and this rule is what makes that a property of the plan rather
 *     than of the planner.
 */
final class JoinPlan
{
    /**
     * @param  array<int, Join>  $joins
     */
    public function __construct(public readonly array $joins = []) {}

    /**
     * @param  array<int, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $joins = [];

        foreach ($data as $join) {
            if (is_array($join)) {
                $joins[] = Join::fromArray($join);
            }
        }

        return new self($joins);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Join $join) => $join->toArray(), $this->joins);
    }

    public function isEmpty(): bool
    {
        return $this->joins === [];
    }

    /**
     * The fields these joins add, field => [alias, column].
     *
     * @return array<string, array{alias: string, column: string}>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->joins as $join) {
            foreach ($join->columns as $field => $column) {
                // First join to offer a field keeps it: the plan is built
                // nearest-first, so the closest source wins.
                $fields[$field] ??= ['alias' => $join->alias, 'column' => $column];
            }
        }

        return $fields;
    }

    public function supplies(string $field): bool
    {
        return isset($this->fields()[$field]);
    }

    /** @return array<int, string> */
    public function tables(): array
    {
        return array_map(fn (Join $join) => $join->table, $this->joins);
    }

    /** Whether any join in the plan rests on an inferred rather than declared key. */
    public function hasInferredKeys(): bool
    {
        foreach ($this->joins as $join) {
            if (! $join->declared) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check every rule above, or throw.
     *
     * Deliberately a hard failure rather than a filter: a plan that names
     * something the schema does not have is not a plan with one bad join in it,
     * it is a plan that came from somewhere it should not have. The caller
     * catches this and falls back to a single-table query.
     *
     * @param  string  $baseTable  The product table the plan hangs off.
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(DiscoveredSchema $schema, string $baseTable, ?int $maxDepth = null): void
    {
        $maxDepth ??= max(1, (int) config('agent.database.discovery.max_join_depth', 2));

        if (count($this->joins) > $maxDepth) {
            throw new InvalidArgumentException('Join plan has more joins than the configured depth allows.');
        }

        if (! $schema->hasTable($baseTable)) {
            throw new InvalidArgumentException('Join plan is anchored to a table outside the discovered schema.');
        }

        // alias => the table it stands for, built up as we go so a join can only
        // reference an alias declared BEFORE it.
        $known = ['' => $baseTable];
        $depth = ['' => 0];
        $aggregated = [];

        foreach ($this->joins as $join) {
            if (! preg_match(Join::ALIAS_PATTERN, $join->alias)) {
                throw new InvalidArgumentException('Join alias is not one this application generates.');
            }

            if (isset($known[$join->alias])) {
                throw new InvalidArgumentException('Join alias is used twice.');
            }

            if (! array_key_exists($join->localAlias, $known)) {
                throw new InvalidArgumentException('Join attaches to an alias that is not defined before it.');
            }

            // An aggregated join is a grouped subquery, not a table: the columns
            // it exposes are folded values, and matching a further join against
            // one of them would be joining on a sum.
            if (isset($aggregated[$join->localAlias])) {
                throw new InvalidArgumentException('Join attaches to an aggregated join.');
            }

            if (! $schema->hasTable($join->table)) {
                throw new InvalidArgumentException('Join names a table outside the discovered schema.');
            }

            $localTable = $known[$join->localAlias];

            if (! $schema->hasColumn($localTable, $join->localColumn)) {
                throw new InvalidArgumentException('Join matches on a column the local table does not have.');
            }

            if (! $schema->hasColumn($join->table, $join->foreignColumn)) {
                throw new InvalidArgumentException('Join matches on a column the joined table does not have.');
            }

            // The row-multiplication guard. Without it the plan is still valid
            // SQL and still returns wrong answers.
            if (! $join->isAggregated() && ! $schema->isUnique($join->table, $join->foreignColumn)) {
                throw new InvalidArgumentException('Join would match more than one row per product.');
            }

            if ($join->columns === []) {
                throw new InvalidArgumentException('Join supplies no columns and has no reason to exist.');
            }

            foreach ($join->columns as $field => $column) {
                if (! is_string($field) || $field === '') {
                    throw new InvalidArgumentException('Join supplies a column under an empty field name.');
                }

                if (! $schema->hasColumn($join->table, $column)) {
                    throw new InvalidArgumentException('Join supplies a column the joined table does not have.');
                }
            }

            if ($join->isAggregated()) {
                $this->assertAggregable($schema, $join);

                $aggregated[$join->alias] = true;
            }

            $depth[$join->alias] = $depth[$join->localAlias] + 1;

            if ($depth[$join->alias] > $maxDepth) {
                throw new InvalidArgumentException('Join chain is deeper than the configured limit.');
            }

            $known[$join->alias] = $join->table;
        }
    }

    /**
     * Rule 6, in one place: what an aggregated join is allowed to be.
     *
     * The uniqueness guarantee an aggregated join rests on comes from grouping
     * by the join key, so the grouping has to be the thing the product row is
     * actually matched against — which is why it must hang off the product table
     * itself, and why nothing may hang off it in turn.
     *
     * @throws InvalidArgumentException
     */
    private function assertAggregable(DiscoveredSchema $schema, Join $join): void
    {
        if (! in_array($join->aggregate, Join::AGGREGATES, true)) {
            throw new InvalidArgumentException('Join uses an aggregate function outside the allowed set.');
        }

        if (! $join->isDirect()) {
            throw new InvalidArgumentException('An aggregated join must attach to the product table itself.');
        }

        $seen = [];

        foreach ($join->columns as $column) {
            // The grouping key is already selected under its own name, and two
            // fields folding the same column would select it twice: either way
            // the derived table would have two columns of one name.
            if ($column === $join->foreignColumn || isset($seen[$column])) {
                throw new InvalidArgumentException('An aggregated join selects the same column twice.');
            }

            if (! $schema->isNumeric($join->table, $column)) {
                throw new InvalidArgumentException('An aggregated join folds a column that does not hold numbers.');
            }

            $seen[$column] = true;
        }
    }

    /**
     * The same check as a question rather than an exception, for callers that
     * are choosing between plans rather than executing one.
     */
    public function isValid(DiscoveredSchema $schema, string $baseTable, ?int $maxDepth = null): bool
    {
        try {
            $this->assertValid($schema, $baseTable, $maxDepth);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
