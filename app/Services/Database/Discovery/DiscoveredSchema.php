<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\DatabaseDriver;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DiscoveredSchema
{
    private function __construct(
        private readonly array $tables,
        private readonly array $foreignKeys,
        private readonly array $rowCounts,
        private readonly array $uniqueColumns = [],
    ) {}

    public static function fromDriver(DatabaseDriver $driver): self
    {
        $tables = [];

        foreach ($driver->describeSchema() as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $tables[$name] = [
                'name' => $name,
                'type' => (string) ($table['type'] ?? 'table'),
                'columns' => array_values($table['columns'] ?? []),
            ];
        }

        return new self(
            tables: $tables,
            foreignKeys: self::readForeignKeys($driver, $tables),
            rowCounts: self::degrade($driver, fn () => $driver->approximateRowCounts(), 'row estimates'),
            uniqueColumns: self::readUniqueColumns($driver, $tables),
        );
    }

    public static function fromArray(array $data): self
    {

        $tables = [];

        foreach (self::arrayOf($data, 'tables') as $table) {
            if (! is_array($table)) {
                continue;
            }

            $name = is_scalar($table['name'] ?? null) ? (string) $table['name'] : '';

            if ($name === '') {
                continue;
            }

            $columns = [];
            foreach (self::arrayOf($table, 'columns') as $column) {
                $columnName = is_array($column) && is_scalar($column['name'] ?? null)
                    ? (string) $column['name']
                    : '';

                if ($columnName === '') {
                    continue;
                }

                $columns[] = [
                    'name' => $columnName,
                    'type' => (string) ($column['type'] ?? ''),
                    'numeric' => (bool) ($column['numeric'] ?? false),
                ];
            }

            $tables[$name] = [
                'name' => $name,
                'type' => ($table['type'] ?? '') === 'view' ? 'view' : 'table',
                'columns' => $columns,
            ];
        }

        $foreignKeys = [];
        foreach (self::arrayOf($data, 'foreign_keys') as $key) {
            if (is_array($key)) {
                $foreignKeys[] = ForeignKey::fromArray($key);
            }
        }

        $rowCounts = [];
        foreach (self::arrayOf($data, 'row_counts') as $table => $count) {
            if (is_numeric($count)) {
                $rowCounts[(string) $table] = (int) $count;
            }
        }

        $unique = [];
        foreach (self::arrayOf($data, 'unique_columns') as $key) {
            if (is_string($key) && $key !== '') {
                $unique[$key] = true;
            }
        }

        return new self($tables, $foreignKeys, $rowCounts, $unique);
    }

    public function toArray(): array
    {
        return [
            'tables' => array_values($this->tables),
            'foreign_keys' => array_map(fn (ForeignKey $key) => $key->toArray(), $this->foreignKeys),
            'row_counts' => $this->rowCounts,
            'unique_columns' => array_keys($this->uniqueColumns),
        ];
    }

    public function isUnique(string $table, string $column): bool
    {
        return isset($this->uniqueColumns[$table.'.'.$column]);
    }

    public function withInferredKeys(array $keys): self
    {
        $known = [];
        foreach ($this->foreignKeys as $key) {
            $known[$key->table.'.'.$key->column] = true;
        }

        $added = [];
        foreach ($keys as $key) {

            if (! $this->hasColumn($key->table, $key->column)
                || ! $this->hasColumn($key->foreignTable, $key->foreignColumn)
                || isset($known[$key->table.'.'.$key->column])) {
                continue;
            }

            $known[$key->table.'.'.$key->column] = true;
            $added[] = $key;
        }

        return new self($this->tables, [...$this->foreignKeys, ...$added], $this->rowCounts, $this->uniqueColumns);
    }

    public function only(array $tables): self
    {
        $keep = array_flip($tables);

        $kept = array_intersect_key($this->tables, $keep);

        $keys = array_values(array_filter(
            $this->foreignKeys,
            fn (ForeignKey $key) => isset($keep[$key->table], $keep[$key->foreignTable]),
        ));

        $unique = [];
        foreach ($this->uniqueColumns as $reference => $yes) {
            [$table] = explode('.', $reference, 2);

            if (isset($keep[$table])) {
                $unique[$reference] = true;
            }
        }

        return new self($kept, $keys, array_intersect_key($this->rowCounts, $keep), $unique);
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        foreach ($this->tables[$table]['columns'] ?? [] as $candidate) {
            if ($candidate['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    public function columns(string $table): array
    {
        return $this->tables[$table]['columns'] ?? [];
    }

    public function columnNames(string $table): array
    {
        return array_column($this->columns($table), 'name');
    }

    public function column(string $table, string $column): ?array
    {
        foreach ($this->columns($table) as $candidate) {
            if ($candidate['name'] === $column) {
                return $candidate;
            }
        }

        return null;
    }

    public function isNumeric(string $table, string $column): bool
    {
        return (bool) ($this->column($table, $column)['numeric'] ?? false);
    }

    public function typeOf(string $table): string
    {
        return $this->tables[$table]['type'] ?? 'table';
    }

    public function isView(string $table): bool
    {
        return $this->typeOf($table) === 'view';
    }

    public function rowCount(string $table): ?int
    {
        return $this->rowCounts[$table] ?? null;
    }

    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function hasDeclaredForeignKeys(): bool
    {
        foreach ($this->foreignKeys as $key) {
            if ($key->declared) {
                return true;
            }
        }

        return false;
    }

    public function keysFrom(string $table): array
    {
        return array_values(array_filter(
            $this->foreignKeys,
            fn (ForeignKey $key) => $key->table === $table,
        ));
    }

    public function keysTo(string $table): array
    {
        return array_values(array_filter(
            $this->foreignKeys,
            fn (ForeignKey $key) => $key->foreignTable === $table,
        ));
    }

    public function tableCount(): int
    {
        return count($this->tables);
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    private static function arrayOf(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    private static function readForeignKeys(DatabaseDriver $driver, array $tables): array
    {
        try {
            $rows = $driver->foreignKeys();
        } catch (Throwable $e) {

            Log::info('Foreign keys unreadable; discovery will fall back to name matching', [
                'engine' => $driver->engine(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $byConstraint = [];
        foreach ($rows as $row) {
            $byConstraint[($row['table'] ?? '').'.'.($row['constraint'] ?? '')][] = $row;
        }

        $keys = [];
        foreach ($byConstraint as $group) {
            if (count($group) !== 1) {
                continue;
            }

            $key = ForeignKey::fromRow($group[0]);

            if (isset($tables[$key->table], $tables[$key->foreignTable])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private static function readUniqueColumns(DatabaseDriver $driver, array $tables): array
    {
        $unique = [];

        foreach (self::degrade($driver, fn () => $driver->uniqueColumns(), 'unique keys') as $row) {
            $table = (string) ($row['table'] ?? '');
            $column = (string) ($row['column'] ?? '');

            if (isset($tables[$table]) && $column !== '') {
                $unique[$table.'.'.$column] = true;
            }
        }

        return $unique;
    }

    private static function degrade(DatabaseDriver $driver, callable $read, string $what): array
    {
        try {
            return $read();
        } catch (Throwable $e) {
            Log::info("Optional schema facts unreadable ({$what}); discovery will do without them", [
                'engine' => $driver->engine(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
