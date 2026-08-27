<?php

namespace Tests\Unit\Database;

use App\Services\Database\AbstractPdoDriver;
use App\Services\Database\ConnectionConfig;
use App\Services\Database\MySqlDriver;
use App\Services\Database\PostgresDriver;
use Tests\TestCase;

/**
 * The catalog queries automatic discovery reads the customer's schema with.
 *
 * No server is involved: like the SELECT compiler, these are pure strings, and
 * the properties that matter about them are assertable directly. What matters is
 * that they stay SCOPED to the connected database, that the schema name is bound
 * rather than pasted in, and — for Postgres — that a table is spelled the same
 * way in every one of them, because describeSchema() joins their results on that
 * string.
 */
class SchemaIntrospectionTest extends TestCase
{
    /**
     * Postgres qualifies anything outside `public`. Both the table list and the
     * column list must build that name with the same expression.
     */
    protected const PG_QUALIFIED_NAME = "CASE WHEN table_schema = 'public' THEN table_name "
        ."ELSE table_schema || '.' || table_name END";

    protected function mysql(): MySqlDriver
    {
        return new MySqlDriver(new ConnectionConfig('mysql', 'db.example.com', 3306, 'shop', 'readonly', 'secret'));
    }

    protected function postgres(): PostgresDriver
    {
        return new PostgresDriver(new ConnectionConfig('pgsql', 'db.example.com', 5432, 'shop', 'readonly', 'secret'));
    }

    /**
     * Reach one of the driver's own introspection queries.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function catalogQuery(AbstractPdoDriver $driver, string $method): array
    {
        return (fn () => $this->{$method}())->call($driver);
    }

    public function test_mysql_reads_every_column_in_one_query_scoped_to_the_connected_database(): void
    {
        [$sql, $bindings] = $this->catalogQuery($this->mysql(), 'allColumnsQuery');

        $this->assertStringStartsWith('SELECT', $sql);
        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
        // One query for the whole schema — this is what keeps a 50-table ERP
        // database from costing 50 round trips.
        $this->assertStringContainsString('ORDER BY TABLE_NAME, ORDINAL_POSITION', $sql);

        // The schema is BOUND. A user with rights on several databases still
        // only ever sees the one they named.
        $this->assertStringContainsString('TABLE_SCHEMA = ?', $sql);
        $this->assertSame(['shop'], $bindings);
        $this->assertStringNotContainsString('shop', $sql);
    }

    public function test_mysql_reads_foreign_keys_with_both_ends_pinned_to_the_connected_database(): void
    {
        [$sql, $bindings] = $this->catalogQuery($this->mysql(), 'foreignKeysQuery');

        $this->assertStringContainsString('information_schema.KEY_COLUMN_USAGE', $sql);
        $this->assertStringContainsString('REFERENCED_TABLE_NAME IS NOT NULL', $sql);

        // Both the owning and the referenced side are constrained to the
        // connected schema: a key into another database points at a table the
        // owner was never shown and could not confirm.
        $this->assertStringContainsString('TABLE_SCHEMA = ?', $sql);
        $this->assertStringContainsString('REFERENCED_TABLE_SCHEMA = ?', $sql);
        $this->assertSame(['shop', 'shop'], $bindings);

        // Selected so a caller can tell a composite key from a single-column one.
        $this->assertStringContainsString('CONSTRAINT_NAME AS `constraint`', $sql);
    }

    public function test_mysql_reads_row_estimates_from_the_catalog_rather_than_counting(): void
    {
        [$sql, $bindings] = $this->catalogQuery($this->mysql(), 'rowCountsQuery');

        $this->assertStringContainsString('TABLE_ROWS', $sql);
        $this->assertSame(['shop'], $bindings);

        // An exact count of a ten million row table would cost more than the
        // rest of discovery put together, and only ever breaks a tie.
        $this->assertStringNotContainsStringIgnoringCase('COUNT(', $sql);
    }

    public function test_postgres_spells_a_qualified_table_the_same_way_everywhere(): void
    {
        $driver = $this->postgres();

        // describeSchema() joins the table list and the column list on this
        // string. If the two expressions ever drift, every non-public table
        // silently loses its columns.
        [$tables] = $this->catalogQuery($driver, 'tablesQuery');
        [$columns] = $this->catalogQuery($driver, 'allColumnsQuery');

        $this->assertStringContainsString(self::PG_QUALIFIED_NAME, $tables);
        $this->assertStringContainsString(self::PG_QUALIFIED_NAME, $columns);
        $this->assertStringContainsString('AS "table"', $columns);
    }

    public function test_postgres_introspection_excludes_the_system_schemas(): void
    {
        $driver = $this->postgres();

        foreach (['allColumnsQuery', 'foreignKeysQuery', 'rowCountsQuery'] as $method) {
            [$sql] = $this->catalogQuery($driver, $method);

            $this->assertStringContainsString("'pg_catalog', 'information_schema'", $sql, $method);
            $this->assertStringStartsWith('SELECT', $sql);
        }
    }

    public function test_postgres_reads_foreign_keys_with_the_constraint_name(): void
    {
        [$sql, $bindings] = $this->catalogQuery($this->postgres(), 'foreignKeysQuery');

        $this->assertStringContainsString("tc.constraint_type = 'FOREIGN KEY'", $sql);
        // constraint_column_usage yields a cross product for composite keys, so
        // the constraint name is what lets the caller recognize and skip them.
        $this->assertStringContainsString('tc.constraint_name AS "constraint"', $sql);
        $this->assertSame([], $bindings);
    }

    public function test_no_introspection_query_can_write(): void
    {
        foreach ([$this->mysql(), $this->postgres()] as $driver) {
            foreach (['tablesQuery', 'allColumnsQuery', 'foreignKeysQuery', 'rowCountsQuery'] as $method) {
                [$sql] = $this->catalogQuery($driver, $method);

                $this->assertMatchesRegularExpression('/^\s*SELECT\s/i', $sql);
                $this->assertStringNotContainsString(';', $sql);
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT)\b/i',
                    $sql,
                    $driver->engine().'::'.$method,
                );
            }
        }
    }
}
