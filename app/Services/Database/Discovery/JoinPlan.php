<?php

namespace App\Services\Database\Discovery;

use InvalidArgumentException;

final class JoinPlan
{
    public function __construct(public readonly array $joins = []) {}

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

    public function toArray(): array
    {
        return array_map(fn (Join $join) => $join->toArray(), $this->joins);
    }

    public function isEmpty(): bool
    {
        return $this->joins === [];
    }

    public function fields(): array
    {
        $fields = [];

        foreach ($this->joins as $join) {
            foreach ($join->columns as $field => $column) {

                $fields[$field] ??= ['alias' => $join->alias, 'column' => $column];
            }
        }

        return $fields;
    }

    public function supplies(string $field): bool
    {
        return isset($this->fields()[$field]);
    }

    public function tables(): array
    {
        return array_map(fn (Join $join) => $join->table, $this->joins);
    }

    public function hasInferredKeys(): bool
    {
        foreach ($this->joins as $join) {
            if (! $join->declared) {
                return true;
            }
        }

        return false;
    }

    public function assertValid(DiscoveredSchema $schema, string $baseTable, ?int $maxDepth = null): void
    {
        $maxDepth ??= max(1, (int) config('agent.database.discovery.max_join_depth', 2));

        if (count($this->joins) > $maxDepth) {
            throw new InvalidArgumentException('Join plan has more joins than the configured depth allows.');
        }

        if (! $schema->hasTable($baseTable)) {
            throw new InvalidArgumentException('Join plan is anchored to a table outside the discovered schema.');
        }

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

            if ($column === $join->foreignColumn || isset($seen[$column])) {
                throw new InvalidArgumentException('An aggregated join selects the same column twice.');
            }

            if (! $schema->isNumeric($join->table, $column)) {
                throw new InvalidArgumentException('An aggregated join folds a column that does not hold numbers.');
            }

            $seen[$column] = true;
        }
    }

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
