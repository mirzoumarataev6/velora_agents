<?php

namespace App\Services\Database;

use PDO;

/**
 * MySQL 5.7+/8 and MariaDB.
 *
 * Introspection is information_schema, scoped to the connected database — a
 * user with rights on several schemas still only ever sees the one they named,
 * so the table picker cannot become a tour of the server.
 */
class MySqlDriver extends AbstractPdoDriver
{
    public const DEFAULT_PORT = 3306;

    public function engine(): string
    {
        return 'mysql';
    }

    protected function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->host,
            $this->config->port,
            $this->config->database,
        );
    }

    protected function extraOptions(): array
    {
        if (! $this->config->ssl) {
            return [];
        }

        $options = [];

        // Verification needs a CA bundle: mysqlnd will not fall back to the
        // system store. With one configured we verify the server properly; with
        // none we still encrypt, and the tab says so — encrypted-but-unverified
        // beats plaintext, and pretending otherwise would be worse than either.
        if ($ca = config('agent.database.ssl_ca')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } else {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return $options;
    }

    protected function quoteChar(): string
    {
        return '`';
    }

    protected function tablesQuery(): array
    {
        return [
            'SELECT TABLE_NAME AS name, '
                ."CASE WHEN TABLE_TYPE = 'VIEW' THEN 'view' ELSE 'table' END AS type "
                .'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->config->database],
        ];
    }

    protected function columnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type '
                .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
                .'ORDER BY ORDINAL_POSITION',
            [$schema !== '' ? $schema : $this->config->database, $table],
        ];
    }

    protected function allColumnsQuery(): array
    {
        return [
            'SELECT TABLE_NAME AS `table`, COLUMN_NAME AS name, DATA_TYPE AS type '
                .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? '
                .'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$this->config->database],
        ];
    }

    protected function foreignKeysQuery(): array
    {
        return [
            'SELECT CONSTRAINT_NAME AS `constraint`, TABLE_NAME AS `table`, COLUMN_NAME AS `column`, '
                .'REFERENCED_TABLE_NAME AS foreign_table, REFERENCED_COLUMN_NAME AS foreign_column '
                .'FROM information_schema.KEY_COLUMN_USAGE '
                // Both ends pinned to the connected database. A key pointing at
                // another schema describes a table the picker never offered, so
                // following it would join something the owner cannot see.
                .'WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? '
                .'AND REFERENCED_TABLE_NAME IS NOT NULL '
                .'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            [$this->config->database, $this->config->database],
        ];
    }

    protected function uniqueColumnsQuery(): array
    {
        return [
            // STATISTICS covers the primary key and every unique index, which is
            // wider than table_constraints and is what actually guarantees at
            // most one row per value.
            'SELECT TABLE_NAME AS `table`, COLUMN_NAME AS `column`, INDEX_NAME AS `key` '
                .'FROM information_schema.STATISTICS '
                .'WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0 '
                .'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$this->config->database],
        ];
    }

    protected function rowCountsQuery(): array
    {
        return [
            // TABLE_ROWS is the storage engine's estimate — exact for MyISAM,
            // approximate for InnoDB, NULL for a view. All three are fine for a
            // tie-break, and none of them touches a data page.
            'SELECT TABLE_NAME AS `table`, TABLE_ROWS AS `rows` '
                .'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$this->config->database],
        ];
    }

    protected function numericTypes(): array
    {
        return ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'];
    }

    protected function lowerText(string $quotedColumn): string
    {
        // CAST first: LOWER() on a numeric SKU column is a no-op in MySQL but an
        // error in Postgres, and both drivers must behave the same way.
        return 'LOWER(CAST('.$quotedColumn.' AS CHAR))';
    }

    protected function orderTerm(string $quotedColumn, string $direction): string
    {
        // MySQL has no NULLS LAST, so the null test leads the sort — a product
        // with no price is never the answer to "what's your cheapest".
        return $quotedColumn.' IS NULL, '.$quotedColumn.' '.strtoupper($direction);
    }

    protected function selectPrefix(int $timeoutMs): string
    {
        // MySQL 5.7.8+ honours this; MariaDB ignores an unknown hint as a
        // comment and gets its ceiling from max_statement_time below instead.
        return '/*+ MAX_EXECUTION_TIME('.$timeoutMs.') */ ';
    }

    protected function sessionStatements(int $timeoutMs): array
    {
        return [
            // Read-only INTENT, enforced by the server. The customer should
            // still supply a read-only user — this is defence in depth, not a
            // substitute for the privilege.
            'SET SESSION TRANSACTION READ ONLY',
            'SET SESSION max_execution_time = '.$timeoutMs,
            // MariaDB's equivalent, in seconds. Refused by MySQL, which is why
            // each statement is executed and caught independently.
            'SET SESSION max_statement_time = '.max(1, (int) ceil($timeoutMs / 1000)),
        ];
    }
}
