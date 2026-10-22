<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\LiveProductSource;
use App\Services\Dataset\ColumnMapper;

class RelationResolver
{
    protected const JOINABLE_FIELDS = ['name', 'category', 'stock', 'price', 'description', 'sku', 'url', 'image_url'];

    protected const AGGREGATABLE_FIELDS = ['stock' => 'sum'];

    public function __construct(protected ColumnRoleResolver $roles) {}

    public function infer(DiscoveredSchema $schema): DiscoveredSchema
    {
        $inferred = [];

        foreach ($schema->tableNames() as $table) {
            foreach ($schema->columns($table) as $column) {
                $target = $this->targetFor($schema, $table, $column);

                if ($target !== null) {
                    $inferred[] = new ForeignKey(
                        table: $table,
                        column: $column['name'],
                        foreignTable: $target[0],
                        foreignColumn: $target[1],
                        declared: false,
                    );
                }
            }
        }

        return $schema->withInferredKeys($inferred);
    }

    public function plan(DiscoveredSchema $schema, string $table, ColumnRoles $roles, ?int $maxDepth = null): JoinPlan
    {
        $maxDepth ??= max(1, (int) config('agent.database.discovery.max_join_depth', 2));

        $missing = [];
        foreach (self::JOINABLE_FIELDS as $field) {
            if (! $roles->has($field)) {
                $missing[$field] = true;
            }
        }

        if ($missing === [] || ! $schema->hasTable($table)) {
            return new JoinPlan;
        }

        $joins = [];

        $reached = ['' => [$table, 0]];
        $used = [$table => true];

        for ($depth = 1; $depth <= $maxDepth && $missing !== []; $depth++) {
            foreach ($this->aliasesAtDepth($reached, $depth - 1) as $localAlias) {
                [$localTable] = $reached[$localAlias];

                foreach ($this->safeEdgesFrom($schema, $localTable) as $edge) {
                    if ($missing === [] || count($joins) >= $maxDepth) {
                        break 2;
                    }

                    [$targetTable, $localColumn, $foreignColumn, $declared, $asDimension, $aggregate] = $edge;

                    if (isset($used[$targetTable])) {
                        continue;
                    }

                    if ($aggregate && $localAlias !== '') {
                        continue;
                    }

                    $folds = null;

                    if ($aggregate) {
                        $folded = $this->aggregable($schema, $targetTable, $missing, $foreignColumn);
                        $supplies = $folded === null ? [] : $folded['columns'];
                        $folds = $folded['aggregate'] ?? null;
                    } else {
                        $supplies = $this->supplied($schema, $targetTable, $missing, $asDimension);
                    }

                    if ($supplies === []) {
                        continue;
                    }

                    $alias = 'j'.count($joins);

                    $joins[] = new Join(
                        table: $targetTable,
                        alias: $alias,
                        localAlias: $localAlias,
                        localColumn: $localColumn,
                        foreignColumn: $foreignColumn,
                        columns: $supplies,
                        declared: $declared,
                        aggregate: $folds,
                    );

                    $used[$targetTable] = true;
                    $reached[$alias] = [$targetTable, $depth];

                    foreach ($supplies as $field => $column) {
                        unset($missing[$field]);
                    }
                }
            }
        }

        $plan = new JoinPlan($joins);

        return $plan->isValid($schema, $table, $maxDepth) ? $plan : new JoinPlan;
    }

    public function nameSource(DiscoveredSchema $schema, string $table, ?int $maxDepth = null): ?string
    {
        return $this->fieldSource($schema, $table, 'name', $maxDepth);
    }

    protected function safeEdgesFrom(DiscoveredSchema $schema, string $table): array
    {
        $edges = [];
        $aggregable = [];

        foreach ($schema->keysFrom($table) as $key) {
            if ($schema->isUnique($key->foreignTable, $key->foreignColumn)) {

                $isDimension = ! $schema->isUnique($table, $key->column);

                $edges[] = [$key->foreignTable, $key->column, $key->foreignColumn, $key->declared, $isDimension, false];
            }
        }

        foreach ($schema->keysTo($table) as $key) {
            if ($schema->isUnique($key->table, $key->column)) {
                $edges[] = [$key->table, $key->foreignColumn, $key->column, $key->declared, false, false];

                continue;
            }

            if ($this->foldsIntoStock($key->table)) {
                $aggregable[] = [$key->table, $key->foreignColumn, $key->column, $key->declared, false, true];
            }
        }

        return [...$edges, ...$aggregable];
    }

    protected function supplied(DiscoveredSchema $schema, string $table, array $missing, bool $asDimension): array
    {
        $roles = $this->roles->resolve($schema, $table);

        $fields = array_keys($missing);
        $supplies = [];

        if ($asDimension) {
            $column = $roles->column('category') ?? $roles->column('name');

            if (isset($missing['category']) && $column !== null) {
                $supplies['category'] = $column;
            }

            if (! TableWords::describes($table, TableWords::PRODUCT)) {
                return $supplies;
            }

            $fields = array_diff($fields, ['name', 'category']);
        }

        foreach ($fields as $field) {
            $column = $roles->column($field);

            if ($column !== null) {
                $supplies[$field] = $column;
            }
        }

        return $supplies;
    }

    protected function foldsIntoStock(string $table): bool
    {
        return TableWords::describes($table, TableWords::STOCK) && $this->couldHoldStock($table);
    }

    protected function couldHoldStock(string $table): bool
    {
        $words = TableWords::of($table);

        return ! TableWords::any($words, TableWords::TRANSACTION)
            && ! TableWords::any($words, TableWords::SERVICE);
    }

    protected function aggregable(DiscoveredSchema $schema, string $table, array $missing, string $groupBy): ?array
    {
        $roles = $this->roles->resolve($schema, $table);

        $columns = [];
        $aggregate = null;

        foreach (self::AGGREGATABLE_FIELDS as $field => $function) {
            if (! isset($missing[$field]) || ($aggregate !== null && $aggregate !== $function)) {
                continue;
            }

            $column = $roles->column($field);

            if ($column === null || $column === $groupBy || ! $schema->isNumeric($table, $column)) {
                continue;
            }

            $columns[$field] = $column;
            $aggregate = $function;
        }

        return $aggregate === null ? null : ['columns' => $columns, 'aggregate' => $aggregate];
    }

    public function stockNotes(DiscoveredSchema $schema, string $table, ColumnRoles $roles, JoinPlan $plan): array
    {

        if ($roles->has('stock') || $plan->supplies('stock')) {
            return [];
        }

        $inPlan = array_flip($plan->tables());
        $notes = [];
        $seen = [];

        foreach ([...$schema->keysTo($table), ...$schema->keysFrom($table)] as $key) {
            $related = $key->table === $table ? $key->foreignTable : $key->table;

            if ($related === $table || isset($inPlan[$related]) || isset($seen[$related])) {
                continue;
            }

            $seen[$related] = true;

            $column = $this->roles->resolve($schema, $related)->column('stock');

            if ($column === null || ! $this->couldHoldStock($related)) {
                continue;
            }

            $notes[] = [

                'code' => $schema->isNumeric($related, $column)
                    ? 'stock_table_not_joined'
                    : 'stock_table_not_numeric',
                'params' => ['table' => $related, 'column' => $column],
            ];
        }

        return $notes;
    }

    public function fieldSource(DiscoveredSchema $schema, string $table, string $field, ?int $maxDepth = null): ?string
    {
        $plan = $this->plan($schema, $table, new ColumnRoles, $maxDepth);

        foreach ($plan->joins as $join) {
            if (isset($join->columns[$field])) {
                return $join->table;
            }
        }

        return null;
    }

    protected function targetFor(DiscoveredSchema $schema, string $table, array $column): ?array
    {
        if (! $column['numeric']) {
            return null;
        }

        $folded = ColumnMapper::fold($column['name']);

        if (! str_ends_with($folded, ' id')) {
            return null;
        }

        $stem = trim(substr($folded, 0, -3));

        if ($stem === '') {
            return null;
        }

        $matches = [];

        foreach ($schema->tableNames() as $candidate) {
            if ($candidate === $table || ! $this->nameMatchesStem($candidate, $stem)) {
                continue;
            }

            foreach (['id', $column['name']] as $target) {
                if ($schema->hasColumn($candidate, $target) && $schema->isUnique($candidate, $target)) {
                    $matches[] = [$candidate, $target];
                    break;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    protected function nameMatchesStem(string $table, string $stem): bool
    {
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        $name = ColumnMapper::fold($name);

        $words = array_values(array_filter(explode(' ', $name), fn ($word) => $word !== ''));
        $last = $words === [] ? '' : $words[count($words) - 1];

        foreach ([$name, $last] as $subject) {
            if ($subject === '') {
                continue;
            }

            if ($subject === $stem || $subject === $stem.'s' || $subject === $stem.'es') {
                return true;
            }

            if (str_ends_with($stem, 'y') && $subject === substr($stem, 0, -1).'ies') {
                return true;
            }
        }

        return false;
    }

    protected function aliasesAtDepth(array $reached, int $depth): array
    {
        $aliases = [];

        foreach ($reached as $alias => [$table, $reachedAt]) {
            if ($reachedAt === $depth) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    public function coveredFields(ColumnRoles $roles, JoinPlan $plan): array
    {
        $fields = [];

        foreach (LiveProductSource::FIELDS as $field) {
            if ($roles->has($field) || $plan->supplies($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
