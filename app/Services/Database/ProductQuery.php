<?php

namespace App\Services\Database;

use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\JoinPlan;
use InvalidArgumentException;

final class ProductQuery
{
    public const OPERATORS = ['=', '>', '>=', '<', '<='];

    public readonly array $columns;

    public readonly array $conditions;

    public readonly array $textGroups;

    public readonly ?ColumnRef $orderColumn;

    public readonly JoinPlan $joins;

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

    public function isJoined(): bool
    {
        return ! $this->joins->isEmpty();
    }
}
