<?php

namespace App\Services\Database;

use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\JoinPlan;
use InvalidArgumentException;

/**
 * A product search, expressed as validated structure rather than SQL.
 *
 * This type is the boundary the model's output can never cross. LiveProductSource
 * builds one of these from the search_products arguments; every column named in
 * it has already been looked up in the stored mapping and the discovered schema,
 * and every operator comes from OPERATORS below. The driver then compiles it
 * into one SELECT with bound values.
 *
 * The constructor re-checks both of those invariants rather than trusting its
 * caller — the cost is a few array lookups per search, and the payoff is that
 * "can a column name reach the SQL unchecked" has a single, testable answer.
 *
 * JOINS widen what a query can name: another table, the pair of columns the two
 * are matched on, and the columns that table contributes. None of those appear
 * in `columns`, so they cannot be covered by the closed-set check below — which
 * is why a query WITH joins must be handed the discovered schema, and refuses to
 * exist without one. A search over a single table needs no schema and behaves
 * exactly as it did before joins were possible.
 */
final class ProductQuery
{
    /** The only comparisons a search can express. */
    public const OPERATORS = ['=', '>', '>=', '<', '<='];

    /** field => ColumnRef. The closed set of identifiers this query may reference. */
    public readonly array $columns;

    /** @var array<int, array{column: ColumnRef, operator: string, value: mixed}> */
    public readonly array $conditions;

    /** @var array<int, array{columns: array<int, ColumnRef>, value: string}> */
    public readonly array $textGroups;

    public readonly ?ColumnRef $orderColumn;

    public readonly JoinPlan $joins;

    /**
     * @param  string  $table  Table or view name, optionally schema-qualified.
     * @param  array<string, ColumnRef|array|string>  $columns  field => column. A plain string is a
     *                                                          column on the product table itself.
     * @param  array<int, array{column: ColumnRef|array|string, operator: string, value: mixed}>  $conditions
     * @param  array<int, array{columns: array<int, ColumnRef|array|string>, value: string}>  $textGroups  Each group is
     *                                                                                                     AND-ed with the rest and OR-ed within itself, as a lowercased LIKE.
     * @param  string  $orderDirection  'asc' or 'desc'.
     * @param  int  $limit  Already clamped by the caller; re-clamped here.
     * @param  JoinPlan|null  $joins  Tables to bring alongside the product table.
     * @param  DiscoveredSchema|null  $schema  Required when $joins is non-empty: it is what
     *                                         the joined identifiers are checked against.
     */
    public function __construct(
        public readonly string $table,
        array $columns,
        array $conditions = [],
        array $textGroups = [],
        ColumnRef|array|string|null $orderColumn = null,
        public readonly string $orderDirection = 'asc',
        public readonly int $limit = 1,
        ?JoinPlan $joins = null,
        ?DiscoveredSchema $schema = null,
    ) {
        if ($columns === []) {
            throw new InvalidArgumentException('A product query must select at least one column.');
        }

        $this->joins = $joins ?? new JoinPlan;

        // The joins are checked FIRST, because everything after this depends on
        // which aliases legitimately exist.
        if (! $this->joins->isEmpty()) {
            if ($schema === null) {
                throw new InvalidArgumentException('A query with joins must be validated against a discovered schema.');
            }

            $this->joins->assertValid($schema, $this->table);
        }

        $aliases = ['' => true];
        foreach ($this->joins->joins as $join) {
            $aliases[$join->alias] = true;
        }

        $resolved = [];
        foreach ($columns as $field => $column) {
            $ref = ColumnRef::make($column);

            if ($ref->column === '') {
                throw new InvalidArgumentException('A selected column has no name.');
            }

            if (! isset($aliases[$ref->alias])) {
                throw new InvalidArgumentException('A selected column names a table that is not in this query.');
            }

            $resolved[$field] = $ref;
        }

        $this->columns = $resolved;

        // Membership is by (table, column), not by column name: with two tables
        // in one query, a bare `name` no longer identifies anything.
        $allowed = [];
        foreach ($resolved as $ref) {
            $allowed[$ref->key()] = true;
        }

        $checkedConditions = [];
        foreach ($conditions as $condition) {
            $ref = ColumnRef::make($condition['column'] ?? '');

            if (! isset($allowed[$ref->key()])) {
                throw new InvalidArgumentException('Condition names a column outside the mapping.');
            }

            if (! in_array($condition['operator'] ?? '', self::OPERATORS, true)) {
                throw new InvalidArgumentException('Condition uses an operator outside the allowed set.');
            }

            $checkedConditions[] = [
                'column' => $ref,
                'operator' => $condition['operator'],
                'value' => $condition['value'] ?? null,
            ];
        }

        $this->conditions = $checkedConditions;

        $checkedGroups = [];
        foreach ($textGroups as $group) {
            $refs = [];

            foreach ($group['columns'] ?? [] as $column) {
                $ref = ColumnRef::make($column);

                if (! isset($allowed[$ref->key()])) {
                    throw new InvalidArgumentException('Text match names a column outside the mapping.');
                }

                $refs[] = $ref;
            }

            $checkedGroups[] = ['columns' => $refs, 'value' => (string) ($group['value'] ?? '')];
        }

        $this->textGroups = $checkedGroups;

        $order = $orderColumn === null ? null : ColumnRef::make($orderColumn);

        if ($order !== null && ! isset($allowed[$order->key()])) {
            throw new InvalidArgumentException('Order names a column outside the mapping.');
        }

        $this->orderColumn = $order;

        if (! in_array($this->orderDirection, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be asc or desc.');
        }

        if ($this->limit < 1) {
            throw new InvalidArgumentException('Limit must be at least 1.');
        }
    }

    /** Whether this search reads more than the product table. */
    public function isJoined(): bool
    {
        return ! $this->joins->isEmpty();
    }
}
