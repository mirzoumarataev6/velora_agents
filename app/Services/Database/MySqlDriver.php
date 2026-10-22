<?php

namespace App\Services\Database;

use PDO;

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

                .'WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? '
                .'AND REFERENCED_TABLE_NAME IS NOT NULL '
                .'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            [$this->config->database, $this->config->database],
        ];
    }

    protected function uniqueColumnsQuery(): array
    {
        return [

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

        return 'LOWER(CAST('.$quotedColumn.' AS CHAR))';
    }

    protected function orderTerm(string $quotedColumn, string $direction): string
    {

        return $quotedColumn.' IS NULL, '.$quotedColumn.' '.strtoupper($direction);
    }

    protected function selectPrefix(int $timeoutMs): string
    {

        return '/*+ MAX_EXECUTION_TIME('.$timeoutMs.') */ ';
    }

    protected function sessionStatements(int $timeoutMs): array
    {
        return [

            'SET SESSION TRANSACTION READ ONLY',
            'SET SESSION max_execution_time = '.$timeoutMs,

            'SET SESSION max_statement_time = '.max(1, (int) ceil($timeoutMs / 1000)),
        ];
    }
}
