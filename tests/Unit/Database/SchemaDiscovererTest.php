<?php

namespace Tests\Unit\Database;

use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\Discovery\DiscoveryResult;
use App\Services\Database\Discovery\SchemaDiscoverer;
use App\Services\Database\Discovery\TableCandidate;
use App\Services\Llm\LlmProviderInterface;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * The discovery pass end to end: read, infer, rank, and only then — maybe — ask
 * a model.
 *
 * The property most of these tests exist to hold is that step four is SKIPPED.
 * A shop whose tables are called `products` and `categories` must never cost a
 * model call, and must never have its answer depend on one.
 */
class SchemaDiscovererTest extends TestCase
{
    protected function discoverer(): SchemaDiscoverer
    {
        return app(SchemaDiscoverer::class);
    }

    /**
     * A three-table shop: products, their categories, their stock.
     */
    protected function driver(array $overrides = []): FakeDatabaseDriver
    {
        return new FakeDatabaseDriver(
            tables: $overrides['tables'] ?? [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'stocks', 'type' => 'table'],
                ['name' => 'orders', 'type' => 'table'],
            ],
            rows: $overrides['rows'] ?? [],
            schema: $overrides['schema'] ?? [
                'products' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'Название', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'Цена', 'type' => 'decimal', 'numeric' => true],
                    ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
                ],
                'categories' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'Название', 'type' => 'varchar', 'numeric' => false],
                ],
                'stocks' => [
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'Остаток', 'type' => 'int', 'numeric' => true],
                ],
                'orders' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'Название', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'Цена', 'type' => 'decimal', 'numeric' => true],
                ],
            ],
            keys: $overrides['keys'] ?? [
                ['table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id', 'foreign_table' => 'products', 'foreign_column' => 'id'],
            ],
            counts: $overrides['counts'] ?? ['products' => 3200],
            unique: $overrides['unique'] ?? [
                ['table' => 'products', 'column' => 'id'],
                ['table' => 'categories', 'column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id'],
                ['table' => 'orders', 'column' => 'id'],
            ],
        );
    }

    public function test_a_three_table_catalog_is_found_and_composed_in_one_pass(): void
    {
        $result = $this->discoverer()->discover($this->driver());

        $this->assertFalse($result->isEmpty());
        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
        $this->assertFalse($result->ambiguous);

        $best = $result->best();
        $this->assertSame('products', $best->table);
        $this->assertSame(TableCandidate::HIGH, $best->confidence);

        // The product's own columns from its own table...
        $this->assertSame('Название', $best->roles->column('name'));
        $this->assertSame('Цена', $best->roles->column('price'));

        // ...and the rest composed from the tables the schema says they live in.
        $summary = $best->joinSummary();
        $this->assertEqualsCanonicalizing(
            [['field' => 'category', 'table' => 'categories', 'declared' => true, 'aggregated' => false],
                ['field' => 'stock', 'table' => 'stocks', 'declared' => true, 'aggregated' => false]],
            $summary,
        );

        // One stock row per product here, so nothing is added up — and nothing
        // is left unsaid either.
        $this->assertSame([], $best->notes);

        // The surrogate `id` is not offered as an SKU — see
        // ColumnRoleResolver::isSurrogateKey().
        $this->assertSame(['name', 'price', 'category', 'stock'], array_keys($best->columns()));
        $this->assertFalse($best->hasInferredRelations());
        $this->assertSame(3200, $best->rowCount);
    }

    public function test_an_orders_table_is_never_proposed(): void
    {
        $result = $this->discoverer()->discover($this->driver());

        $this->assertNull($result->proposalFor('orders'));
        $this->assertNull($result->proposalFor('categories'));
    }

    public function test_a_legacy_schema_with_no_keys_is_still_composed_and_flagged_as_guesswork(): void
    {
        $result = $this->discoverer()->discover($this->driver(['keys' => []]));

        $best = $result->best();

        $this->assertSame('products', $best->table);
        // Relationships were worked out from column names, so the joins built on
        // them are marked as inferred and the confidence stops short of high.
        $this->assertTrue($best->hasInferredRelations());
        $this->assertSame(TableCandidate::MEDIUM, $best->confidence);
        $this->assertContains('no_declared_keys', array_column($best->reasons, 'code'));

        // The category still gets pulled in — the fallback is a lower confidence,
        // not a lesser feature.
        $this->assertContains('category', array_keys($best->columns()));
    }

    public function test_two_plausible_catalogs_are_both_proposed(): void
    {
        $result = $this->discoverer()->discover($this->driver([
            'tables' => [
                ['name' => 'products_retail', 'type' => 'table'],
                ['name' => 'products_wholesale', 'type' => 'table'],
            ],
            'schema' => [
                'products_retail' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                ],
                'products_wholesale' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                ],
            ],
            'keys' => [],
            'counts' => [],
            'unique' => [['table' => 'products_retail', 'column' => 'id'], ['table' => 'products_wholesale', 'column' => 'id']],
        ]));

        $this->assertTrue($result->ambiguous);
        $this->assertCount(2, $result->proposals);
        $this->assertNotNull($result->proposalFor('products_retail'));
        $this->assertNotNull($result->proposalFor('products_wholesale'));
    }

    public function test_a_database_too_large_to_scan_says_so(): void
    {
        config(['agent.database.discovery.max_tables' => 2]);

        $tables = [];
        for ($i = 0; $i < 5; $i++) {
            $tables[] = ['name' => "t{$i}", 'type' => 'table'];
        }

        try {
            $this->discoverer()->discover($this->driver(['tables' => $tables, 'schema' => []]));
            $this->fail('A schema over the budget should be refused.');
        } catch (DatabaseConnectionException $e) {
            $this->assertSame(DatabaseConnectionException::SCHEMA_TOO_LARGE, $e->reason);
        }
    }

    public function test_an_unreachable_database_fails_the_scan_rather_than_returning_nothing(): void
    {
        $driver = $this->driver();
        $driver->failWith = new DatabaseConnectionException(DatabaseConnectionException::UNREACHABLE);

        $this->expectException(DatabaseConnectionException::class);

        $this->discoverer()->discover($driver);
    }

    public function test_a_schema_with_nothing_recognizable_proposes_nothing(): void
    {
        $result = $this->discoverer()->discover($this->driver([
            'tables' => [['name' => 'sessions', 'type' => 'table']],
            'schema' => ['sessions' => [['name' => 'payload', 'type' => 'text', 'numeric' => false]]],
            'keys' => [],
            'counts' => [],
            'unique' => [],
        ]));

        // Nothing is proposed rather than something weak being proposed: the
        // owner is sent to the advanced panel, not given a wrong answer.
        $this->assertTrue($result->isEmpty());
        $this->assertNull($result->best());
    }

    public function test_the_preview_reads_real_rows_through_the_query_the_agent_would_run(): void
    {
        $driver = $this->driver(['rows' => [
            ['name' => 'Куртка зимняя', 'price' => '4500.00', 'stock' => 3, 'category' => 'Верхняя одежда'],
            ['name' => 'Шапка', 'price' => '450.00', 'stock' => 0, 'category' => 'Аксессуары'],
        ]]);

        $discoverer = $this->discoverer();
        $result = $discoverer->discover($driver);

        $rows = $discoverer->preview($driver, $result->schema, $result->best());

        $this->assertCount(2, $rows);
        $this->assertSame('Куртка зимняя', $rows[0]['name']);
        $this->assertSame('Верхняя одежда', $rows[0]['category']);

        // Built through the real ProductQuery, joins and all — what is previewed
        // is what would be run.
        $query = $driver->lastQuery();
        $this->assertTrue($query->isJoined());
        $this->assertSame('products', $query->table);
        $this->assertSame(5, $query->limit);
    }

    public function test_preview_values_are_shortened(): void
    {
        $driver = $this->driver(['rows' => [
            ['name' => str_repeat('я', 500), 'price' => '1', 'stock' => 1, 'category' => 'x'],
        ]]);

        $discoverer = $this->discoverer();
        $result = $discoverer->discover($driver);

        $rows = $discoverer->preview($driver, $result->schema, $result->best());

        // A product row can hold a 50KB description; the owner needs to
        // recognize their catalog, not read it.
        $this->assertLessThanOrEqual(80, mb_strlen($rows[0]['name']));
    }

    public function test_the_model_is_not_consulted_when_the_schema_answers_the_question(): void
    {
        config(['agent.database.discovery.use_llm' => true]);

        // Any call at all fails the test: a confident deterministic answer must
        // not cost a model call.
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldNotReceive('generate');
        });

        $result = $this->discoverer()->discover($this->driver());

        $this->assertSame('products', $result->best()->table);
        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
    }

    public function test_the_model_is_not_consulted_at_all_while_the_feature_is_off(): void
    {
        config(['agent.database.discovery.use_llm' => false]);

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldNotReceive('generate');
        });

        // Deliberately ambiguous — even so, the switch is off.
        $result = $this->discoverer()->discover($this->driver([
            'tables' => [['name' => 'tbl_a', 'type' => 'table'], ['name' => 'tbl_b', 'type' => 'table']],
            'schema' => [
                'tbl_a' => [['name' => 'name', 'type' => 'varchar', 'numeric' => false], ['name' => 'price', 'type' => 'decimal', 'numeric' => true]],
                'tbl_b' => [['name' => 'name', 'type' => 'varchar', 'numeric' => false], ['name' => 'price', 'type' => 'decimal', 'numeric' => true]],
            ],
            'keys' => [],
            'counts' => [],
            'unique' => [],
        ]));

        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
    }
}
