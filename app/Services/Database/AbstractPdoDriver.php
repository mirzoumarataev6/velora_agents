<?php

namespace App\Services\Database;

use App\Services\Bi\Query\ExternalMySqlQueryCompiler;
use App\Services\Bi\Query\ExternalPostgresQueryCompiler;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Database\Discovery\Join;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Everything the PDO-backed drivers share: connecting, the read-only and
 * timeout session setup, identifier quoting, and the one SELECT compiler.
 *
 * Subclasses supply only what genuinely differs between engines — the DSN, the
 * quote character, the introspection queries, and the two or three expression
 * shapes (case-folding cast, NULLS-last ordering, statement-timeout syntax) that
 * standard SQL does not agree on.
 *
 * THE SAFETY CONTRACT, in one place:
 *
 *  1. Only SELECT is ever built. There is no code path in this class or its
 *     subclasses that emits INSERT, UPDATE, DELETE, DROP or ALTER, and
 *     assertSelectOnly() re-checks the finished string before it is executed.
 *  2. Values are always bound, never interpolated. The single exception is the
 *     row limit, which is cast to int and range-checked first.
 *  3. Identifiers are never taken from a caller's string. They come from a
 *     ProductQuery, whose constructor has already checked every one of them
 *     against the stored mapping, and are then quoted with the engine's own
 *     escaping.
 *  4. The session is put into read-only mode where the engine supports it, so a
 *     write would be refused by the server even if 1-3 somehow failed.
 */
abstract class AbstractPdoDriver implements DatabaseDriver
{
    public function queryBi(QuerySpec $spec): array
    {
        return $this->guarded(function () use ($spec) {
            $compiler = $this->engine() === 'pgsql'
                ? new ExternalPostgresQueryCompiler
                : new ExternalMySqlQueryCompiler;
            $compiled = $compiler->compile($spec);
            $this->assertSelectOnly($compiled->sql);

            return $this->select($compiled->sql, $compiled->bindings);
        });
    }

    /**
     * Characters an identifier may never contain, whatever the engine. Quoting
     * already makes these harmless; rejecting them means a name that could only
     * have come from tampering never reaches the server at all.
     */
    protected const FORBIDDEN_IDENTIFIER_PATTERN = '/[\x00-\x1F\x7F`"\'\\\\;]/u';

    /**
     * What the product table is called inside a JOINED query.
     *
     * Ours, not the customer's — two tables in one query can each have a column
     * called `name`, and the thing that tells them apart must not be a string
     * we read off somebody's schema.
     */
    protected const BASE_ALIAS = 't';

    protected ?PDO $pdo = null;

    public function __construct(protected readonly ConnectionConfig $config) {}

    abstract public function engine(): string;

    /** The PDO DSN for this engine, built from $this->config. */
    abstract protected function dsn(): string;

    /** The character this engine quotes identifiers with. */
    abstract protected function quoteChar(): string;

    /**
     * SQL listing the readable tables and views, returning `name` and `type`.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    abstract protected function tablesQuery(): array;

    /**
     * SQL describing one table's columns, returning `name` and `type`.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    abstract protected function columnsQuery(string $schema, string $table): array;

    /**
     * SQL describing the columns of EVERY readable table at once, returning
     * `table`, `name` and `type`.
     *
     * The `table` value must be spelled exactly the way tablesQuery() spells it
     * — Postgres qualifies anything outside `public`, and the two result sets are
     * joined on that string.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    abstract protected function allColumnsQuery(): array;

    /**
     * SQL listing declared foreign keys, returning `constraint`, `table`,
     * `column`, `foreign_table` and `foreign_column`.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    abstract protected function foreignKeysQuery(): array;

    /**
     * SQL listing the columns of unique constraints and indexes, returning
     * `table`, `column` and `key` — the key name, so multi-column keys can be
     * told apart from single-column ones.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    abstract protected function uniqueColumnsQuery(): array;

    /**
     * SQL returning the engine's own row estimates as `table` and `rows`.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    abstract protected function rowCountsQuery(): array;

    /** Column type names this engine treats as numeric, lowercased. */
    abstract protected function numericTypes(): array;

    /** An expression that folds one already-quoted column to lowercase text. */
    abstract protected function lowerText(string $quotedColumn): string;

    /** An ORDER BY term that sorts NULLs last in both directions. */
    abstract protected function orderTerm(string $quotedColumn, string $direction): string;

    /** Statements that make the session read-only and time-limited, best effort. */
    abstract protected function sessionStatements(int $timeoutMs): array;

    /** Text inserted directly after SELECT (MySQL's optimizer timeout hint). */
    protected function selectPrefix(int $timeoutMs): string
    {
        return '';
    }

    /**
     * Engine-specific PDO options — TLS settings live here, since only MySQL
     * configures them through the options array.
     *
     * Merged UNDER the shared options, so a driver cannot turn off real
     * prepared statements or the exception error mode.
     *
     * @return array<int, mixed>
     */
    protected function extraOptions(): array
    {
        return [];
    }

    public function testConnection(): void
    {
        $this->guarded(function () {
            $pdo = $this->connect();
            // Cheap, universally supported, and it proves the credentials were
            // accepted rather than merely that the port answered.
            $pdo->query('SELECT 1')?->fetch();
        });
    }

    public function listTables(): array
    {
        return $this->guarded(function () {
            [$sql, $bindings] = $this->tablesQuery();

            $rows = $this->select($sql, $bindings);

            $tables = [];
            foreach ($rows as $row) {
                $tables[] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => (string) ($row['type'] ?? 'table'),
                ];
            }

            return $tables;
        });
    }

    public function describeTable(string $table): array
    {
        return $this->guarded(function () use ($table) {
            [$schema, $name] = $this->splitTable($table);

            [$sql, $bindings] = $this->columnsQuery($schema, $name);
            $rows = $this->select($sql, $bindings);

            if ($rows === []) {
                throw new DatabaseConnectionException(
                    DatabaseConnectionException::SCHEMA_CHANGED,
                    "Table [{$table}] has no readable columns."
                );
            }

            $columns = [];
            foreach ($rows as $row) {
                $type = strtolower((string) ($row['type'] ?? ''));
                $columns[] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => $type,
                    'numeric' => in_array($type, $this->numericTypes(), true),
                ];
            }

            $limit = (int) config('agent.database.sample_rows', 5);
            $sample = $this->select(
                'SELECT * FROM '.$this->qualify($table).' LIMIT '.max(1, min($limit, 20)),
                []
            );

            return ['columns' => $columns, 'sample' => $sample];
        });
    }

    public function describeSchema(): array
    {
        return $this->guarded(function () {
            [$sql, $bindings] = $this->tablesQuery();
            $tableRows = $this->select($sql, $bindings);

            // Checked BEFORE the columns query, which is the expensive one: a
            // database with thousands of tables would otherwise have every one of
            // their columns pulled across the wire only to be refused here.
            $max = max(1, (int) config('agent.database.discovery.max_tables', 300));

            if (count($tableRows) > $max) {
                throw new DatabaseConnectionException(
                    DatabaseConnectionException::SCHEMA_TOO_LARGE,
                    'Database exposes '.count($tableRows)." tables, over the discovery budget of {$max}."
                );
            }

            $tables = [];
            foreach ($tableRows as $row) {
                $name = (string) ($row['name'] ?? '');

                if ($name !== '') {
                    $tables[$name] = [
                        'name' => $name,
                        'type' => (string) ($row['type'] ?? 'table'),
                        'columns' => [],
                    ];
                }
            }

            [$sql, $bindings] = $this->allColumnsQuery();

            foreach ($this->select($sql, $bindings) as $row) {
                $table = (string) ($row['table'] ?? '');

                // A column belonging to something tablesQuery() did not report is
                // dropped rather than inventing a table: the table list is the
                // authority on what this user may see.
                if (! isset($tables[$table])) {
                    continue;
                }

                $type = strtolower((string) ($row['type'] ?? ''));

                $tables[$table]['columns'][] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => $type,
                    'numeric' => in_array($type, $this->numericTypes(), true),
                ];
            }

            return array_values($tables);
        });
    }

    public function foreignKeys(): array
    {
        return $this->guarded(function () {
            [$sql, $bindings] = $this->foreignKeysQuery();

            $keys = [];

            foreach ($this->select($sql, $bindings) as $row) {
                $key = [
                    'constraint' => (string) ($row['constraint'] ?? ''),
                    'table' => (string) ($row['table'] ?? ''),
                    'column' => (string) ($row['column'] ?? ''),
                    'foreign_table' => (string) ($row['foreign_table'] ?? ''),
                    'foreign_column' => (string) ($row['foreign_column'] ?? ''),
                ];

                // A half-populated row describes no relationship anyone could
                // follow, and a join built from one would name an empty
                // identifier. Dropped here rather than defended against later.
                if ($key['table'] !== '' && $key['column'] !== ''
                    && $key['foreign_table'] !== '' && $key['foreign_column'] !== '') {
                    $keys[] = $key;
                }
            }

            return $keys;
        });
    }

    public function uniqueColumns(): array
    {
        return $this->guarded(function () {
            [$sql, $bindings] = $this->uniqueColumnsQuery();

            // Grouped by the key they belong to, because a two-column unique key
            // says nothing about either column on its own — and a join built on
            // one half of it would duplicate rows exactly as if there were no
            // key at all.
            $byKey = [];

            foreach ($this->select($sql, $bindings) as $row) {
                $table = (string) ($row['table'] ?? '');
                $column = (string) ($row['column'] ?? '');
                $key = (string) ($row['key'] ?? '');

                if ($table !== '' && $column !== '') {
                    $byKey[$table.'.'.$key][] = ['table' => $table, 'column' => $column];
                }
            }

            $unique = [];
            foreach ($byKey as $columns) {
                if (count($columns) === 1) {
                    $unique[] = $columns[0];
                }
            }

            return $unique;
        });
    }

    public function approximateRowCounts(): array
    {
        return $this->guarded(function () {
            [$sql, $bindings] = $this->rowCountsQuery();

            $counts = [];

            foreach ($this->select($sql, $bindings) as $row) {
                $table = (string) ($row['table'] ?? '');
                $rows = $row['rows'] ?? null;

                // NULL for a view in MySQL, -1 for a never-analyzed table in
                // Postgres 14+. Both mean "no estimate", which is not the same
                // number as zero and must not rank a table last.
                if ($table !== '' && $rows !== null && (int) $rows >= 0) {
                    $counts[$table] = (int) $rows;
                }
            }

            return $counts;
        });
    }

    public function query(ProductQuery $query): array
    {
        return $this->guarded(function () use ($query) {
            [$sql, $bindings] = $this->compile($query);

            return $this->select($sql, $bindings);
        });
    }

    /**
     * Compile a ProductQuery into one parameterized SELECT.
     *
     * Public so the SQL this feature can produce is directly assertable in
     * tests, without a live server: the shape of the string and the fact that
     * every value is a placeholder are the security property, and a property you
     * cannot test is one you are only hoping for.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile(ProductQuery $query): array
    {
        $bindings = [];

        // A single-table search is compiled exactly as it always was, with no
        // alias on the product table: the SQL a working connection produces
        // today does not change because joins became possible.
        $base = $query->isJoined() ? self::BASE_ALIAS : '';

        $select = [];
        foreach ($query->columns as $field => $column) {
            $select[] = $this->reference($column, $base).' AS '.$this->quote($field);
        }

        $where = [];

        foreach ($query->textGroups as $group) {
            $ors = [];
            foreach ($group['columns'] as $column) {
                $ors[] = $this->lowerText($this->reference($column, $base)).' LIKE ?';
                $bindings[] = '%'.$group['value'].'%';
            }

            if ($ors !== []) {
                $where[] = '('.implode(' OR ', $ors).')';
            }
        }

        foreach ($query->conditions as $condition) {
            $where[] = $this->reference($condition['column'], $base).' '.$condition['operator'].' ?';
            $bindings[] = $condition['value'];
        }

        $sql = 'SELECT '.$this->selectPrefix($this->timeoutMs()).implode(', ', $select)
            .' FROM '.$this->qualify($query->table);

        if ($base !== '') {
            $sql .= ' AS '.$this->quote($base);
        }

        foreach ($query->joins->joins as $join) {
            // LEFT, always: a product with no category row is still a product,
            // and an INNER join would silently drop it from the catalog.
            $sql .= ' LEFT JOIN '.$this->joinTarget($join).' AS '.$this->quote($join->alias)
                .' ON '.$this->reference(new ColumnRef($join->localColumn, $join->localAlias), $base)
                .' = '.$this->quote($join->alias).'.'.$this->quote($join->foreignColumn);
        }

        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        if ($query->orderColumn !== null) {
            $sql .= ' ORDER BY '.$this->orderTerm($this->reference($query->orderColumn, $base), $query->orderDirection);
        }

        // Inlined rather than bound: several PDO drivers refuse a placeholder in
        // LIMIT with emulation off. It is an int cast and range-checked on the
        // line above, so there is no string here to inject into.
        $sql .= ' LIMIT '.max(1, min($query->limit, 1000));

        $this->assertSelectOnly($sql);

        return [$sql, $bindings];
    }

    /**
     * What a join reads FROM: the table itself, or — for an aggregated join —
     * a grouped subquery over it.
     *
     *     (SELECT `product_id`, SUM(`quantity`) AS `quantity`
     *        FROM `stocks` GROUP BY `product_id`)
     *
     * The point of the derived table is arithmetic, not convenience: a shop with
     * one stock row per warehouse has no unique key to join on, and this shape
     * produces exactly one row per product with the warehouses added up, which
     * is the answer a customer asking "is it in stock" actually wants.
     *
     * Every identifier in it comes from a Join that JoinPlan::assertValid() has
     * already checked against the discovered schema, and the aggregate function
     * is re-checked here against the same closed set rather than trusted.
     */
    protected function joinTarget(Join $join): string
    {
        if (! $join->isAggregated()) {
            return $this->qualify($join->table);
        }

        if (! in_array($join->aggregate, Join::AGGREGATES, true)) {
            throw new InvalidArgumentException('Refusing to compile an aggregate function outside the allowed set.');
        }

        // The grouping key first, under its own name: it is what the product row
        // is matched against, and the ON clause names it exactly as it does for
        // a plain join.
        $select = [$this->quote($join->foreignColumn)];

        foreach ($join->columns as $column) {
            $select[] = $this->aggregateTerm($join->aggregate, $this->quote($column)).' AS '.$this->quote($column);
        }

        return '(SELECT '.implode(', ', $select)
            .' FROM '.$this->qualify($join->table)
            .' GROUP BY '.$this->quote($join->foreignColumn).')';
    }

    /** One aggregate applied to an already-quoted column. */
    protected function aggregateTerm(string $aggregate, string $quotedColumn): string
    {
        return match ($aggregate) {
            'sum' => 'SUM('.$quotedColumn.')',
            default => throw new InvalidArgumentException('Unsupported aggregate function.'),
        };
    }

    /**
     * The last line of the SELECT-only defence.
     *
     * Nothing above can produce a write — this exists so that a future edit
     * which accidentally could fails here instead of on the customer's data.
     */
    protected function assertSelectOnly(string $sql): void
    {
        if (! preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new InvalidArgumentException('Refusing to run a statement that is not a SELECT.');
        }

        // Scan with the quoted identifiers removed. A shop is entirely within
        // its rights to have a column called "create" or a table called
        // "order_update", and quoting already renders those inert — it is the
        // UNQUOTED text that would have to contain a keyword for this to be a
        // second statement.
        $char = $this->quoteChar();
        $bare = preg_replace('/'.preg_quote($char, '/').'(?:[^'.preg_quote($char, '/').']|'.preg_quote($char.$char, '/').')*'.preg_quote($char, '/').'/u', '?', $sql) ?? $sql;

        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|CALL|MERGE|REPLACE|INTO\s+OUTFILE)\b/i', $bare)) {
            throw new InvalidArgumentException('Refusing to run a statement containing a write keyword.');
        }

        if (str_contains($sql, ';')) {
            throw new InvalidArgumentException('Refusing to run a statement containing a statement separator.');
        }
    }

    /**
     * Quote one identifier the way this engine does, doubling the quote
     * character inside it. Rejects anything with a control character, a quote
     * or a semicolon in it first — those cannot occur in a name that came from
     * our own discovery, so their presence means the value did not.
     */
    protected function quote(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || mb_strlen($identifier) > 128) {
            throw new InvalidArgumentException('Identifier is empty or too long.');
        }

        if (preg_match(self::FORBIDDEN_IDENTIFIER_PATTERN, $identifier)) {
            throw new InvalidArgumentException('Identifier contains characters that are never valid here.');
        }

        $char = $this->quoteChar();

        return $char.str_replace($char, $char.$char, $identifier).$char;
    }

    /**
     * One column reference, quoted and qualified for the query it appears in.
     *
     * Unqualified when there are no joins (nothing to be ambiguous with, and the
     * SQL stays byte-for-byte what it was), and qualified by the owning table's
     * alias otherwise. Both parts are quoted separately — a dot is a separator
     * here, never a character inside a name.
     */
    protected function reference(ColumnRef $ref, string $baseAlias): string
    {
        $alias = $ref->isBase() ? $baseAlias : $ref->alias;

        return $alias === ''
            ? $this->quote($ref->column)
            : $this->quote($alias).'.'.$this->quote($ref->column);
    }

    /** Quote a possibly schema-qualified table name, part by part. */
    protected function qualify(string $table): string
    {
        [$schema, $name] = $this->splitTable($table);

        return $schema === ''
            ? $this->quote($name)
            : $this->quote($schema).'.'.$this->quote($name);
    }

    /**
     * @return array{0: string, 1: string} [schema, table]
     */
    protected function splitTable(string $table): array
    {
        $parts = explode('.', $table, 2);

        return count($parts) === 2
            ? [trim($parts[0]), trim($parts[1])]
            : ['', trim($parts[0])];
    }

    protected function timeoutMs(): int
    {
        return max(1, (int) config('agent.database.query_timeout_seconds', 5)) * 1000;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, array<string, mixed>>
     */
    protected function select(string $sql, array $bindings): array
    {
        $statement = $this->connect()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    protected function connect(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        SafeHostGuard::assertDialable($this->config->host);

        $connectTimeout = max(1, (int) config('agent.database.connect_timeout_seconds', 5));

        $this->pdo = new PDO($this->dsn(), $this->config->username, $this->config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => $connectTimeout,
            // Real prepared statements: the server keeps the SQL and the values
            // apart rather than trusting us to have quoted them.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ] + $this->extraOptions());

        foreach ($this->sessionStatements($this->timeoutMs()) as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (Throwable $e) {
                // Managed hosts sometimes refuse SET. Losing the read-only hint
                // or the server-side timeout weakens defence in depth; it does
                // not make the query unsafe, and refusing to connect over it
                // would break otherwise fine customer databases.
                Log::info('Live DB session setup statement refused', [
                    'engine' => $this->engine(),
                    'statement' => $statement,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->pdo;
    }

    /**
     * Run a unit of driver work, turning any PDO failure into a classified
     * DatabaseConnectionException. Nothing raw escapes the driver layer.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    protected function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (DatabaseConnectionException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw DatabaseConnectionException::fromDriverFailure($e);
        }
    }
}
