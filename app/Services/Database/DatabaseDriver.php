<?php

namespace App\Services\Database;

use App\Services\Bi\Query\QuerySpec;

/**
 * One database engine, as this feature needs to see it.
 *
 * The interface is deliberately narrow: seven verbs, none of which accepts SQL.
 * A driver owns its own introspection queries (information_schema for MySQL,
 * pg_catalog for Postgres), its own identifier quoting, and its own read-only
 * and timeout session setup — and nothing else. Adding MSSQL or another engine
 * later is one more class implementing this and one line in DriverManager, with
 * no change to the source, the controller or the tool.
 *
 * Every method throws DatabaseConnectionException with a classified reason
 * rather than letting a PDOException escape, so no caller ever has to decide
 * what a raw driver error means.
 */
interface DatabaseDriver
{
    /**
     * A BI aggregate over a table registered for this owner's BI connection.
     * The driver accepts validated structure, never SQL from a caller.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryBi(QuerySpec $spec): array;

    /** The engine key this driver is registered under ('mysql', 'pgsql'). */
    public function engine(): string;

    /**
     * Open a connection and prove it works, then close it. Used by the
     * "Test connection" button BEFORE anything is saved.
     *
     * @throws DatabaseConnectionException
     */
    public function testConnection(): void;

    /**
     * Tables and views in the connected database that the user can read.
     *
     * @return array<int, array{name: string, type: string}>
     *
     * @throws DatabaseConnectionException
     */
    public function listTables(): array;

    /**
     * The columns of one table or view, plus a few real rows so the owner can
     * see what they are mapping.
     *
     * @return array{columns: array<int, array{name: string, type: string, numeric: bool}>, sample: array<int, array<string, mixed>>}
     *
     * @throws DatabaseConnectionException
     */
    public function describeTable(string $table): array;

    /**
     * The WHOLE readable schema — every table and view with its columns — in one
     * query set rather than one round trip per table.
     *
     * This is what automatic discovery reads. It exists separately from
     * describeTable() because the manual flow describes exactly one table the
     * owner already picked, while discovery has to look at all of them before it
     * can suggest which one holds products. On a 50-table ERP schema this is two
     * catalog queries, not fifty.
     *
     * No row data is read, ever. Names and types only.
     *
     * @return array<int, array{name: string, type: string, columns: array<int, array{name: string, type: string, numeric: bool}>}>
     *
     * @throws DatabaseConnectionException SCHEMA_TOO_LARGE when the database has
     *                                     more tables than the configured budget.
     */
    public function describeSchema(): array;

    /**
     * The declared foreign keys of the connected database.
     *
     * Relationships are the primary signal for how discovery joins tables:
     * products.category_id → categories.id is a fact the schema states, not a
     * guess we make from a name. A database with none (legacy MyISAM, most CMS
     * exports) is normal and handled by the caller, which falls back to name
     * matching at a lower confidence.
     *
     * `constraint` is returned so a caller can recognize a COMPOSITE key: the
     * same constraint name over several rows. Discovery follows single-column
     * relationships only, and needs to be able to tell the difference.
     *
     * @return array<int, array{constraint: string, table: string, column: string, foreign_table: string, foreign_column: string}>
     *
     * @throws DatabaseConnectionException
     */
    public function foreignKeys(): array;

    /**
     * Single-column UNIQUE constraints and indexes, table by table.
     *
     * This is what makes a composed join safe rather than merely plausible. A
     * join is only ever allowed onto a column the engine itself guarantees holds
     * at most one row per value — otherwise `stocks` with a row per warehouse
     * would return the same product three times, and the 20-row cap would spend
     * itself on repeats.
     *
     * Only single-column keys are reported: a join we would build is one column
     * wide, so a two-column unique key does not make that join unique.
     *
     * @return array<int, array{table: string, column: string}>
     *
     * @throws DatabaseConnectionException
     */
    public function uniqueColumns(): array;

    /**
     * Approximate row counts, table => estimate.
     *
     * From the engine's own statistics (information_schema.TABLE_ROWS,
     * pg_class.reltuples), never SELECT COUNT(*) — an exact count of a ten
     * million row table would cost more than the whole rest of discovery. The
     * numbers only ever break a tie between candidates, so being off by a third
     * costs nothing and scanning would cost the connection.
     *
     * @return array<string, int>
     *
     * @throws DatabaseConnectionException
     */
    public function approximateRowCounts(): array;

    /**
     * Run one product search. The spec is a validated structure, never SQL —
     * the driver compiles it into a single parameterized SELECT.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws DatabaseConnectionException
     */
    public function query(ProductQuery $query): array;
}
