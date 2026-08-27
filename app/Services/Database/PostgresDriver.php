<?php

namespace App\Services\Database;

/**
 * PostgreSQL.
 *
 * Introspection comes from information_schema with the system schemas excluded,
 * and non-public schemas are reported qualified ("sales.products") so the owner
 * can pick a table outside `public` and the mapping still names it
 * unambiguously.
 */
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

        // With a CA configured, verify the chain and the hostname; without one,
        // require encryption but accept an unverified certificate.
        $ca = config('agent.database.ssl_ca');

        return $ca
            ? $dsn.';sslmode=verify-full;sslrootcert='.$ca
            : $dsn.';sslmode=require';
    }

    protected function quoteChar(): string
    {
        return '"';
    }

    /**
     * The one expression every introspection query names a table with.
     *
     * describeSchema() joins the table list, the column list and the key lists
     * on this string, so all of them have to spell "sales.products" identically.
     * Written once here rather than four times so they cannot drift apart.
     */
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

    /**
     * The `table` expression here is character-for-character the one in
     * tablesQuery(): describeSchema() joins the two result sets on that string,
     * so "sales.products" has to be spelled the same way in both.
     */
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
        // constraint_column_usage does not pair its rows with key_column_usage's
        // for a COMPOSITE key — it yields the cross product of both sides. That
        // is why the constraint name is selected: a caller that sees one
        // constraint over several rows knows to skip it, and single-column keys
        // (all discovery ever follows) are unaffected.
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
        // PRIMARY KEY and UNIQUE CONSTRAINTS. A bare unique INDEX with no
        // constraint behind it is missed, which costs a join we could have
        // offered — never a join we should not have.
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
            // reltuples is the planner's estimate, refreshed by ANALYZE. It is
            // -1 on a relation that has never been analyzed in PG 14+, which the
            // caller reads as "no estimate" rather than as an empty table.
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
        // The cast is not optional here: lower() has no signature for a numeric
        // argument, so a shop whose SKU column is an integer would error out.
        return 'LOWER(CAST('.$quotedColumn.' AS TEXT))';
    }

    protected function orderTerm(string $quotedColumn, string $direction): string
    {
        // Postgres defaults to NULLS LAST ascending and NULLS FIRST descending;
        // stating it keeps both directions consistent with MySQL and with the
        // uploaded-catalog source.
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
