<?php

namespace App\Services\Database;

class PostgresDriver extends AbstractPdoDriver
{
    public const DEFAULT_PORT = 5432;

    public function engine(): string
    {
        return 'pgsql';
    }

    protected function dsn(): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
            $this->config->host,
            $this->config->port,
            $this->config->database,
            max(1, (int) config('agent.database.connect_timeout_seconds', 5)),
        );

        if (! $this->config->ssl) {
            return $dsn.';sslmode=prefer';
        }

        $ca = config('agent.database.ssl_ca');

        return $ca
            ? $dsn.';sslmode=verify-full;sslrootcert='.$ca
            : $dsn.';sslmode=require';
    }

    protected function quoteChar(): string
    {
        return '"';
    }

    protected static function qualified(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return "CASE WHEN {$prefix}table_schema = 'public' THEN {$prefix}table_name "
            ."ELSE {$prefix}table_schema || '.' || {$prefix}table_name END";
    }

    protected function tablesQuery(): array
    {
        return [
            'SELECT '.self::qualified().' AS name, '
                ."CASE WHEN table_type = 'VIEW' THEN 'view' ELSE 'table' END AS type "
                .'FROM information_schema.tables '
                ."WHERE table_schema NOT IN ('pg_catalog', 'information_schema') "
                .'ORDER BY table_schema, table_name',
            [],
        ];
    }

    protected function columnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT column_name AS name, data_type AS type '
                .'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? '
                .'ORDER BY ordinal_position',
            [$schema !== '' ? $schema : 'public', $table],
        ];
    }

    protected function allColumnsQuery(): array
    {
        return [
            'SELECT '.self::qualified().' AS "table", '
                .'column_name AS name, data_type AS type '
                .'FROM information_schema.columns '
                ."WHERE table_schema NOT IN ('pg_catalog', 'information_schema') "
                .'ORDER BY table_schema, table_name, ordinal_position',
            [],
        ];
    }

    protected function foreignKeysQuery(): array
    {

        return [
            'SELECT tc.constraint_name AS "constraint", '
                .self::qualified('tc').' AS "table", '
                .'kcu.column_name AS "column", '
                .self::qualified('ccu').' AS foreign_table, '
                .'ccu.column_name AS foreign_column '
                .'FROM information_schema.table_constraints tc '
                .'JOIN information_schema.key_column_usage kcu '
                .'ON kcu.constraint_name = tc.constraint_name AND kcu.constraint_schema = tc.constraint_schema '
                .'JOIN information_schema.constraint_column_usage ccu '
                .'ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema '
                ."WHERE tc.constraint_type = 'FOREIGN KEY' "
                ."AND tc.table_schema NOT IN ('pg_catalog', 'information_schema') "
                .'ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position',
            [],
        ];
    }

    protected function uniqueColumnsQuery(): array
    {

        return [
            'SELECT '.self::qualified('tc').' AS "table", '
                .'kcu.column_name AS "column", tc.constraint_name AS "key" '
                .'FROM information_schema.table_constraints tc '
                .'JOIN information_schema.key_column_usage kcu '
                .'ON kcu.constraint_name = tc.constraint_name AND kcu.constraint_schema = tc.constraint_schema '
                ."WHERE tc.constraint_type IN ('PRIMARY KEY', 'UNIQUE') "
                ."AND tc.table_schema NOT IN ('pg_catalog', 'information_schema') "
                .'ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position',
            [],
        ];
    }

    protected function rowCountsQuery(): array
    {
        return [

            "SELECT CASE WHEN n.nspname = 'public' THEN c.relname "
                ."ELSE n.nspname || '.' || c.relname END AS \"table\", "
                .'c.reltuples::bigint AS "rows" '
                .'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
                ."WHERE c.relkind IN ('r', 'p', 'm', 'v') "
                ."AND n.nspname NOT IN ('pg_catalog', 'information_schema')",
            [],
        ];
    }

    protected function numericTypes(): array
    {
        return [
            'smallint', 'integer', 'bigint', 'decimal', 'numeric', 'real',
            'double precision', 'money', 'smallserial', 'serial', 'bigserial',
        ];
    }

    protected function lowerText(string $quotedColumn): string
    {

        return 'LOWER(CAST('.$quotedColumn.' AS TEXT))';
    }

    protected function orderTerm(string $quotedColumn, string $direction): string
    {

        return $quotedColumn.' '.strtoupper($direction).' NULLS LAST';
    }

    protected function sessionStatements(int $timeoutMs): array
    {
        return [
            'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY',
            'SET statement_timeout = '.$timeoutMs,
        ];
    }
}
