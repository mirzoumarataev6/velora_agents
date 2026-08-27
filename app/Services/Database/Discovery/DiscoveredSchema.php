<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseDriver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything we know about a customer's database, read from its own catalog.
 *
 * THIS OBJECT IS THE CLOSED SET.
 *
 * The existing feature's safety rests on one property: no identifier reaches the
 * SQL builder unless we read it off the customer's information_schema ourselves.
 * With a single table and a hand-made mapping that property was easy to hold —
 * the controller compared the mapping against one describeTable() call.
 * Automatic discovery introduces candidate tables, joins and (optionally) a
 * model's suggestion, all of which name identifiers, so the property now needs a
 * place to live. It lives here.
 *
 * Every downstream step — scoring, join planning, LLM-suggestion validation, the
 * confirmation endpoint — asks this object whether a name exists before doing
 * anything with it. Nothing constructs one from user input: the only public
 * builders are fromDriver(), which reads the server, and fromArray(), which
 * reads back a snapshot we ourselves wrote.
 *
 * It holds names and types. It never holds row data and never holds credentials,
 * which is also what makes it the only thing safe to describe to a model.
 */
final class DiscoveredSchema
{
    /**
     * @param  array<string, array{name: string, type: string, columns: array<int, array{name: string, type: string, numeric: bool}>}>  $tables  Keyed by table name, in the order the server reported.
     * @param  array<int, ForeignKey>  $foreignKeys
     * @param  array<string, int>  $rowCounts
     * @param  array<string, bool>  $uniqueColumns  Keyed "table.column".
     */
    private function __construct(
        private readonly array $tables,
        private readonly array $foreignKeys,
        private readonly array $rowCounts,
        private readonly array $uniqueColumns = [],
    ) {}

    /**
     * Read a live database.
     *
     * Only the schema itself is required. Foreign keys and row estimates are
     * enhancements: a MyISAM schema has no keys to report, and a locked-down user
     * may be refused pg_class. Losing either degrades discovery to name-based
     * matching at a lower confidence — which is a supported outcome the owner is
     * told about — so neither is allowed to fail the whole scan.
     *
     * @throws DatabaseConnectionException when the schema itself cannot be read.
     */
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

    /**
     * Rebuild from the snapshot stored on the connection.
     *
     * The stored snapshot is what a later search validates its identifiers
     * against, so it has to reconstruct to exactly the same closed set the
     * owner confirmed against — not a re-read of a schema that may since have
     * changed underneath us.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // Read defensively, element by element. This reads back our own writing,
        // but a snapshot that has been truncated by a column limit, hand-edited,
        // or written by an older version of this code must produce a SMALLER
        // closed set — never a malformed one, and never an entry nobody
        // discovered.
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

    /**
     * The snapshot shape written to `database_connections.schema_snapshot`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tables' => array_values($this->tables),
            'foreign_keys' => array_map(fn (ForeignKey $key) => $key->toArray(), $this->foreignKeys),
            'row_counts' => $this->rowCounts,
            'unique_columns' => array_keys($this->uniqueColumns),
        ];
    }

    /**
     * Does the engine guarantee at most one row per value in this column?
     *
     * The question every composed join has to answer before it is offered. A
     * join onto a non-unique column repeats the product once per matching row,
     * and the search's row cap would then spend itself on duplicates.
     */
    public function isUnique(string $table, string $column): bool
    {
        return isset($this->uniqueColumns[$table.'.'.$column]);
    }

    /**
     * A schema with these relationships added to the declared ones.
     *
     * Used by the no-FK fallback, which infers `product.category_id →
     * categories.id` from naming. Inferred keys are appended, never substituted:
     * a schema that declares its keys keeps them, and the two kinds stay
     * distinguishable by ForeignKey::$declared.
     *
     * @param  array<int, ForeignKey>  $keys
     */
    public function withInferredKeys(array $keys): self
    {
        $known = [];
        foreach ($this->foreignKeys as $key) {
            $known[$key->table.'.'.$key->column] = true;
        }

        $added = [];
        foreach ($keys as $key) {
            // Only real relationships, and never a second one from a column that
            // already has a declared key — the declared one is the fact.
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

    /**
     * The same schema narrowed to a few tables.
     *
     * What gets STORED on a connection is this, not the whole scan. Two reasons,
     * both of which matter:
     *
     *  - Latency. The snapshot is loaded whenever a joined search runs, which is
     *    on the path of a customer's message. A 300-table scan is a megabyte of
     *    JSON; the four tables a confirmed plan actually touches are a few
     *    kilobytes, and they are all the closed-set check needs.
     *  - Restraint. We keep a description of the customer's product tables
     *    because the feature cannot work without one. Keeping a map of their
     *    entire database, including the parts this feature will never read,
     *    would be holding data we have no use for.
     *
     * Keys and unique constraints follow only where both of their ends survive.
     *
     * @param  array<int, string>  $tables
     */
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

    /** Does the customer's schema really contain this table or view? */
    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * Does this table really have this column?
     *
     * The single question every join, every mapping and every model suggestion
     * has to pass before an identifier of theirs is used for anything.
     */
    public function hasColumn(string $table, string $column): bool
    {
        foreach ($this->tables[$table]['columns'] ?? [] as $candidate) {
            if ($candidate['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * One table's columns, in the shape `database_connections.schema_columns`
     * already stores.
     *
     * @return array<int, array{name: string, type: string, numeric: bool}>
     */
    public function columns(string $table): array
    {
        return $this->tables[$table]['columns'] ?? [];
    }

    /** @return array<int, string> */
    public function columnNames(string $table): array
    {
        return array_column($this->columns($table), 'name');
    }

    /**
     * One column's type information, or null when the column does not exist.
     *
     * @return array{name: string, type: string, numeric: bool}|null
     */
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

    /** 'table' or 'view'. */
    public function typeOf(string $table): string
    {
        return $this->tables[$table]['type'] ?? 'table';
    }

    public function isView(string $table): bool
    {
        return $this->typeOf($table) === 'view';
    }

    /** The engine's row estimate, or null when it had none to give. */
    public function rowCount(string $table): ?int
    {
        return $this->rowCounts[$table] ?? null;
    }

    /** @return array<int, ForeignKey> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Whether the schema declares any relationships at all.
     *
     * False is the legacy/MyISAM case the plan calls out: discovery still works,
     * by name matching, and says so with a lower confidence.
     */
    public function hasDeclaredForeignKeys(): bool
    {
        foreach ($this->foreignKeys as $key) {
            if ($key->declared) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relationships leading OUT of this table (products.category_id → …).
     *
     * @return array<int, ForeignKey>
     */
    public function keysFrom(string $table): array
    {
        return array_values(array_filter(
            $this->foreignKeys,
            fn (ForeignKey $key) => $key->table === $table,
        ));
    }

    /**
     * Relationships pointing AT this table (… → products.id).
     *
     * How a stock table that references products is found, and a useful signal
     * that a table is a central entity rather than a lookup.
     *
     * @return array<int, ForeignKey>
     */
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

    /**
     * One key of a decoded snapshot, as an array — [] for anything else.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private static function arrayOf(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * Read the declared keys, dropping composites and anything naming a table
     * this user was not shown.
     *
     * @param  array<string, mixed>  $tables
     * @return array<int, ForeignKey>
     */
    private static function readForeignKeys(DatabaseDriver $driver, array $tables): array
    {
        try {
            $rows = $driver->foreignKeys();
        } catch (Throwable $e) {
            // Nothing to report to the owner: this is the ordinary state of a
            // legacy schema, and the confidence they are shown already reflects
            // having no keys to work from.
            Log::info('Foreign keys unreadable; discovery will fall back to name matching', [
                'engine' => $driver->engine(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        // Group by constraint so multi-column keys can be recognized and skipped
        // — see ForeignKey::fromRow().
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

            // Both ends must be tables the schema read actually returned. A key
            // to something outside that set points at a table the owner can
            // neither see nor confirm.
            if (isset($tables[$key->table], $tables[$key->foreignTable])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $tables
     * @return array<string, bool>
     */
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

    /**
     * Run one OPTIONAL catalog read, returning [] if the server refuses it.
     *
     * Row estimates only break ties, and unique keys only ever ADD a join we are
     * sure of — losing either makes discovery more conservative, never wrong. A
     * user with rights to read columns but not statistics still gets a working
     * scan, which is the whole point of not requiring these.
     *
     * @param  callable(): array<mixed>  $read
     * @return array<mixed>
     */
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
