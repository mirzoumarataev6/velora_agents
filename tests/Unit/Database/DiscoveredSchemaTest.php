<?php

namespace Tests\Unit\Database;

use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\ForeignKey;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * The closed set itself.
 *
 * Everything automatic discovery goes on to do — ranking candidates, planning
 * joins, judging a model's suggestion — asks this object whether an identifier
 * is real. So what is asserted here is mostly what does NOT end up in it: keys
 * we cannot follow, keys to tables the user was never shown, and names nobody
 * read off the customer's own catalog.
 */
class DiscoveredSchemaTest extends TestCase
{
    protected function driver(array $overrides = []): FakeDatabaseDriver
    {
        return new FakeDatabaseDriver(
            tables: $overrides['tables'] ?? [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'v_catalog', 'type' => 'view'],
            ],
            schema: $overrides['schema'] ?? [
                'products' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'title', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                    ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
                ],
                'categories' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                ],
                'v_catalog' => [
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                ],
            ],
            keys: $overrides['keys'] ?? [
                ['table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
            ],
            counts: $overrides['counts'] ?? ['products' => 8400, 'categories' => 26],
        );
    }

    public function test_the_whole_schema_is_read_with_columns_grouped_under_their_own_table(): void
    {
        $schema = DiscoveredSchema::fromDriver($this->driver());

        $this->assertSame(['products', 'categories', 'v_catalog'], $schema->tableNames());
        $this->assertSame(['id', 'title', 'price', 'category_id'], $schema->columnNames('products'));
        $this->assertSame(['id', 'name'], $schema->columnNames('categories'));

        $this->assertTrue($schema->isNumeric('products', 'price'));
        $this->assertFalse($schema->isNumeric('products', 'title'));

        // A well-named view is a first-class candidate later, so its kind has to
        // survive the read rather than being flattened to "table".
        $this->assertTrue($schema->isView('v_catalog'));
        $this->assertFalse($schema->isView('products'));

        $this->assertSame(8400, $schema->rowCount('products'));
        // No estimate is not the same number as zero.
        $this->assertNull($schema->rowCount('v_catalog'));
    }

    public function test_only_identifiers_the_server_reported_are_in_the_closed_set(): void
    {
        $schema = DiscoveredSchema::fromDriver($this->driver());

        $this->assertTrue($schema->hasTable('products'));
        $this->assertTrue($schema->hasColumn('products', 'price'));

        $this->assertFalse($schema->hasTable('users'));
        $this->assertFalse($schema->hasTable('information_schema.tables'));
        $this->assertFalse($schema->hasColumn('products', 'password'));
        // A column that exists — on another table.
        $this->assertFalse($schema->hasColumn('products', 'name'));
        $this->assertFalse($schema->hasColumn('users', 'id'));
        $this->assertFalse($schema->hasColumn('products', '(SELECT password FROM users)'));
    }

    public function test_a_declared_relationship_is_readable_from_both_ends(): void
    {
        $schema = DiscoveredSchema::fromDriver($this->driver());

        $this->assertTrue($schema->hasDeclaredForeignKeys());

        $out = $schema->keysFrom('products');
        $this->assertCount(1, $out);
        $this->assertSame('category_id', $out[0]->column);
        $this->assertSame('categories', $out[0]->foreignTable);
        $this->assertTrue($out[0]->declared);

        $in = $schema->keysTo('categories');
        $this->assertCount(1, $in);
        $this->assertSame('products', $in[0]->table);
    }

    public function test_a_composite_foreign_key_is_not_offered_as_a_join(): void
    {
        // Joining on one column of a two-column key returns the wrong rows
        // silently, which is worse than offering no join at all.
        $schema = DiscoveredSchema::fromDriver($this->driver(['keys' => [
            ['constraint' => 'fk_multi', 'table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
            ['constraint' => 'fk_multi', 'table' => 'products', 'column' => 'title', 'foreign_table' => 'categories', 'foreign_column' => 'name'],
        ]]));

        $this->assertSame([], $schema->foreignKeys());
        $this->assertFalse($schema->hasDeclaredForeignKeys());
    }

    public function test_a_key_pointing_outside_the_visible_schema_is_dropped(): void
    {
        $schema = DiscoveredSchema::fromDriver($this->driver(['keys' => [
            ['table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
            // A table the schema read never reported — following it would join
            // something the owner can neither see nor confirm.
            ['table' => 'products', 'column' => 'owner_id', 'foreign_table' => 'other_db_users', 'foreign_column' => 'id'],
        ]]));

        $this->assertCount(1, $schema->foreignKeys());
        $this->assertSame('categories', $schema->foreignKeys()[0]->foreignTable);
    }

    public function test_a_schema_that_declares_no_keys_is_read_successfully_and_says_so(): void
    {
        // The ordinary legacy/MyISAM case: discovery still runs, by name
        // matching, at a confidence that reflects having no facts to work from.
        $schema = DiscoveredSchema::fromDriver($this->driver(['keys' => []]));

        $this->assertFalse($schema->hasDeclaredForeignKeys());
        $this->assertSame(['products', 'categories', 'v_catalog'], $schema->tableNames());
    }

    public function test_unreadable_keys_and_row_counts_degrade_instead_of_failing_the_scan(): void
    {
        $driver = new class($this->driver()) extends FakeDatabaseDriver
        {
            public function __construct(FakeDatabaseDriver $inner)
            {
                parent::__construct(
                    tables: $inner->tables,
                    schema: $inner->schema,
                );
            }

            public function foreignKeys(): array
            {
                throw new DatabaseConnectionException(DatabaseConnectionException::PERMISSION_DENIED);
            }

            public function approximateRowCounts(): array
            {
                throw new DatabaseConnectionException(DatabaseConnectionException::PERMISSION_DENIED);
            }
        };

        $schema = DiscoveredSchema::fromDriver($driver);

        // A locked-down user who may read columns but not constraints still gets
        // discovery — the schema itself is the only hard requirement.
        $this->assertSame(['products', 'categories', 'v_catalog'], $schema->tableNames());
        $this->assertSame([], $schema->foreignKeys());
        $this->assertNull($schema->rowCount('products'));
    }

    public function test_an_unreadable_schema_does_fail_the_scan(): void
    {
        $driver = $this->driver();
        $driver->failWith = new DatabaseConnectionException(DatabaseConnectionException::UNREACHABLE);

        $this->expectException(DatabaseConnectionException::class);

        DiscoveredSchema::fromDriver($driver);
    }

    public function test_a_database_with_more_tables_than_the_budget_is_refused(): void
    {
        config(['agent.database.discovery.max_tables' => 3]);

        $tables = [];
        for ($i = 0; $i < 4; $i++) {
            $tables[] = ['name' => "t{$i}", 'type' => 'table'];
        }

        try {
            DiscoveredSchema::fromDriver($this->driver(['tables' => $tables, 'schema' => []]));
            $this->fail('A schema over the discovery budget should be refused.');
        } catch (DatabaseConnectionException $e) {
            // Its own reason, not a generic failure: the owner is told to use
            // the manual picker rather than that their database is broken.
            $this->assertSame(DatabaseConnectionException::SCHEMA_TOO_LARGE, $e->reason);
        }
    }

    public function test_inferred_keys_must_name_real_columns_and_never_replace_a_declared_one(): void
    {
        $schema = DiscoveredSchema::fromDriver($this->driver());

        $widened = $schema->withInferredKeys([
            // Both ends real, no declared key on this column — accepted.
            new ForeignKey('categories', 'id', 'products', 'category_id', declared: false),
            // The column already has a DECLARED key. The fact wins.
            new ForeignKey('products', 'category_id', 'v_catalog', 'name', declared: false),
            // Names a column that does not exist — refused like any other
            // unverified identifier.
            new ForeignKey('products', 'brand_id', 'categories', 'id', declared: false),
            // Names a table that does not exist.
            new ForeignKey('products', 'category_id', 'brands', 'id', declared: false),
        ]);

        $this->assertCount(2, $widened->foreignKeys());

        $fromProducts = $widened->keysFrom('products');
        $this->assertCount(1, $fromProducts);
        $this->assertSame('categories', $fromProducts[0]->foreignTable);
        $this->assertTrue($fromProducts[0]->declared);

        $fromCategories = $widened->keysFrom('categories');
        $this->assertCount(1, $fromCategories);
        $this->assertFalse($fromCategories[0]->declared);

        // The original is untouched — it is a value object, not a mutable cache.
        $this->assertCount(1, $schema->foreignKeys());
    }

    public function test_the_stored_snapshot_reconstructs_the_same_closed_set(): void
    {
        $original = DiscoveredSchema::fromDriver($this->driver());

        // What a later search validates identifiers against is the snapshot the
        // owner confirmed, not a fresh read of a schema that may have changed.
        $restored = DiscoveredSchema::fromArray(json_decode(json_encode($original->toArray()), true));

        $this->assertSame($original->tableNames(), $restored->tableNames());
        $this->assertSame($original->columnNames('products'), $restored->columnNames('products'));
        $this->assertTrue($restored->isNumeric('products', 'price'));
        $this->assertTrue($restored->isView('v_catalog'));
        $this->assertSame(8400, $restored->rowCount('products'));
        $this->assertCount(1, $restored->keysFrom('products'));
        $this->assertFalse($restored->hasColumn('products', 'password'));
    }

    public function test_a_snapshot_built_from_junk_yields_an_empty_closed_set(): void
    {
        // fromArray() reads back our own writing, but it must not become a way to
        // introduce an identifier nobody discovered.
        $schema = DiscoveredSchema::fromArray([
            'tables' => [['name' => '', 'columns' => [['name' => 'x']]], 'not-an-array'],
            'foreign_keys' => 'nonsense',
        ]);

        $this->assertTrue($schema->isEmpty());
        $this->assertSame(0, $schema->tableCount());
        $this->assertFalse($schema->hasTable(''));
    }
}
