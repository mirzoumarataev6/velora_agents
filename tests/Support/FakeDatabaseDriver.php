<?php

namespace Tests\Support;

use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseDriver;
use App\Services\Database\ProductQuery;

/**
 * A customer database that does not exist.
 *
 * The suite cannot stand up MySQL and PostgreSQL servers, so the parts that need
 * a live engine are split in two and both halves are tested:
 *
 *  - What SQL the real drivers produce, and what they refuse to produce, is
 *    asserted directly against MySqlDriver/PostgresDriver::compile() in
 *    tests/Unit/Database — no server required, because compilation is pure.
 *  - Everything ABOVE the driver — the controller flow, the mapping validation,
 *    the row cap, tenant isolation, graceful degradation — is exercised through
 *    this fake, which records what it was asked for and can be told to fail the
 *    way a real server fails.
 */
class FakeDatabaseDriver implements DatabaseDriver
{
    /** @var array<int, ProductQuery> */
    public array $queries = [];

    public int $connectionAttempts = 0;

    public ?DatabaseConnectionException $failWith = null;

    /**
     * @param  array<int, array{name: string, type: string}>  $tables
     * @param  array<int, array{name: string, type: string, numeric: bool}>  $columns  The columns EVERY table has, for
     *                                                                                 tests that only care about one. Ignored where $schema is set.
     * @param  array<int, array<string, mixed>>  $sample
     * @param  array<int, array<string, mixed>>  $rows  Returned verbatim by query().
     * @param  array<string, array<int, array{name: string, type: string, numeric: bool}>>  $schema  table => columns, for
     *                                                                                               discovery tests where each table needs its own shape.
     * @param  array<int, array{constraint?: string, table: string, column: string, foreign_table: string, foreign_column: string}>  $keys
     * @param  array<string, int>  $counts
     * @param  array<int, array{table: string, column: string}>  $unique  Single-column unique keys.
     */
    public function __construct(
        public array $tables = [],
        public array $columns = [],
        public array $sample = [],
        public array $rows = [],
        public array $schema = [],
        public array $keys = [],
        public array $counts = [],
        public array $unique = [],
    ) {}

    public function engine(): string
    {
        return 'mysql';
    }

    public function testConnection(): void
    {
        $this->connectionAttempts++;
        $this->maybeFail();
    }

    public function listTables(): array
    {
        $this->maybeFail();

        return $this->tables;
    }

    public function describeTable(string $table): array
    {
        $this->maybeFail();

        // Per-table columns where the test set them up, so the manual flow and
        // automatic discovery see the same database rather than two different
        // ones — which is what lets a test exercise both against one fixture.
        return [
            'columns' => $this->schema[$table] ?? $this->columns,
            'sample' => $this->sample,
        ];
    }

    /**
     * The whole schema, honouring the same table budget the real drivers do —
     * so "a schema too large to scan is refused" is a property of the path under
     * test rather than of the fake being asked politely.
     */
    public function describeSchema(): array
    {
        $this->maybeFail();

        $max = max(1, (int) config('agent.database.discovery.max_tables', 300));

        if (count($this->tables) > $max) {
            throw new DatabaseConnectionException(
                DatabaseConnectionException::SCHEMA_TOO_LARGE,
                'Fake database exposes '.count($this->tables)." tables, over {$max}."
            );
        }

        $schema = [];

        foreach ($this->tables as $table) {
            $schema[] = [
                'name' => $table['name'],
                'type' => $table['type'] ?? 'table',
                // Per-table columns when the test set them up, otherwise the one
                // shared column list the older tests rely on.
                'columns' => $this->schema[$table['name']] ?? $this->columns,
            ];
        }

        return $schema;
    }

    public function foreignKeys(): array
    {
        $this->maybeFail();

        return array_map(fn (array $key) => $key + ['constraint' => 'fk_'.$key['table'].'_'.$key['column']], $this->keys);
    }

    public function uniqueColumns(): array
    {
        $this->maybeFail();

        return $this->unique;
    }

    public function approximateRowCounts(): array
    {
        $this->maybeFail();

        return $this->counts;
    }

    public function query(ProductQuery $query): array
    {
        $this->maybeFail();

        $this->queries[] = $query;

        // Honour the limit so "the cap is enforced" is a property of the whole
        // path, not of the fake being asked nicely.
        return array_slice($this->rows, 0, $query->limit);
    }

    public function lastQuery(): ?ProductQuery
    {
        return $this->queries === [] ? null : $this->queries[array_key_last($this->queries)];
    }

    protected function maybeFail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
