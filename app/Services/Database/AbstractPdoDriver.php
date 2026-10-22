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

    protected const FORBIDDEN_IDENTIFIER_PATTERN = '/[\x00-\x1F\x7F`"\'\\\\;]/u';

    protected const BASE_ALIAS = 't';

    protected ?PDO $pdo = null;

    public function __construct(protected readonly ConnectionConfig $config) {}

    abstract public function engine(): string;

    abstract protected function dsn(): string;

    abstract protected function quoteChar(): string;

    abstract protected function tablesQuery(): array;

    abstract protected function columnsQuery(string $schema, string $table): array;

    abstract protected function allColumnsQuery(): array;

    abstract protected function foreignKeysQuery(): array;

    abstract protected function uniqueColumnsQuery(): array;

    abstract protected function rowCountsQuery(): array;

    abstract protected function numericTypes(): array;

    abstract protected function lowerText(string $quotedColumn): string;

    abstract protected function orderTerm(string $quotedColumn, string $direction): string;

    abstract protected function sessionStatements(int $timeoutMs): array;

    protected function selectPrefix(int $timeoutMs): string
    {
        return '';
    }

    protected function extraOptions(): array
    {
        return [];
    }

    public function testConnection(): void
    {
        $this->guarded(function () {
            $pdo = $this->connect();

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

    public function compile(ProductQuery $query): array
    {
        $bindings = [];

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

        $sql .= ' LIMIT '.max(1, min($query->limit, 1000));

        $this->assertSelectOnly($sql);

        return [$sql, $bindings];
    }

    protected function joinTarget(Join $join): string
    {
        if (! $join->isAggregated()) {
            return $this->qualify($join->table);
        }

        if (! in_array($join->aggregate, Join::AGGREGATES, true)) {
            throw new InvalidArgumentException('Refusing to compile an aggregate function outside the allowed set.');
        }

        $select = [$this->quote($join->foreignColumn)];

        foreach ($join->columns as $column) {
            $select[] = $this->aggregateTerm($join->aggregate, $this->quote($column)).' AS '.$this->quote($column);
        }

        return '(SELECT '.implode(', ', $select)
            .' FROM '.$this->qualify($join->table)
            .' GROUP BY '.$this->quote($join->foreignColumn).')';
    }

    protected function aggregateTerm(string $aggregate, string $quotedColumn): string
    {
        return match ($aggregate) {
            'sum' => 'SUM('.$quotedColumn.')',
            default => throw new InvalidArgumentException('Unsupported aggregate function.'),
        };
    }

    protected function assertSelectOnly(string $sql): void
    {
        if (! preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new InvalidArgumentException('Refusing to run a statement that is not a SELECT.');
        }

        $char = $this->quoteChar();
        $bare = preg_replace('/'.preg_quote($char, '/').'(?:[^'.preg_quote($char, '/').']|'.preg_quote($char.$char, '/').')*'.preg_quote($char, '/').'/u', '?', $sql) ?? $sql;

        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|CALL|MERGE|REPLACE|INTO\s+OUTFILE)\b/i', $bare)) {
            throw new InvalidArgumentException('Refusing to run a statement containing a write keyword.');
        }

        if (str_contains($sql, ';')) {
            throw new InvalidArgumentException('Refusing to run a statement containing a statement separator.');
        }
    }

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

    protected function reference(ColumnRef $ref, string $baseAlias): string
    {
        $alias = $ref->isBase() ? $baseAlias : $ref->alias;

        return $alias === ''
            ? $this->quote($ref->column)
            : $this->quote($alias).'.'.$this->quote($ref->column);
    }

    protected function qualify(string $table): string
    {
        [$schema, $name] = $this->splitTable($table);

        return $schema === ''
            ? $this->quote($name)
            : $this->quote($schema).'.'.$this->quote($name);
    }

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

            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ] + $this->extraOptions());

        foreach ($this->sessionStatements($this->timeoutMs()) as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (Throwable $e) {

                Log::info('Live DB session setup statement refused', [
                    'engine' => $this->engine(),
                    'statement' => $statement,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->pdo;
    }

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
