<?php

namespace Tests\Feature\Database;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Database\ConnectionConfig;
use App\Services\Database\Discovery\DiscoveryProposal;
use App\Services\Database\Discovery\DiscoveryResult;
use App\Services\Database\Discovery\SchemaDiscoverer;
use App\Services\Database\Discovery\TableCandidate;
use App\Services\Database\DriverManager;
use App\Services\Database\MySqlDriver;
use App\Services\Database\PostgresDriver;
use App\Services\Database\ProductQuery;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Discovery against the shape a real shop database actually came in.
 *
 * Six tables — products, categories, stocks, suppliers, customers, orders — with
 * one detail that decides almost everything: `stocks.product_id` has a foreign
 * key and NO unique index, because the shop has more than one warehouse.
 *
 * Two things went wrong on that database, and both are asserted here:
 *
 *  1. `stocks` was dropped from the plan, correctly refusing to multiply rows
 *     and incorrectly leaving the agent unable to answer "is it in stock" — with
 *     nothing said about it, which reads as "this shop keeps no stock data".
 *  2. `customers` and `suppliers` were offered as product tables, because a name
 *     column was the whole of the evidence. Their previews read "Название товара:
 *     Иван Петров".
 */
class RealShopDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The database as reported.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function driver(array $overrides = []): FakeDatabaseDriver
    {
        return new FakeDatabaseDriver(
            tables: [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'stocks', 'type' => 'table'],
                ['name' => 'suppliers', 'type' => 'table'],
                ['name' => 'customers', 'type' => 'table'],
                ['name' => 'orders', 'type' => 'table'],
            ],
            rows: $overrides['rows'] ?? [],
            schema: [
                'products' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                    ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'supplier_id', 'type' => 'int', 'numeric' => true],
                ],
                'categories' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                ],
                // The table this whole file is about: a foreign key to products
                // and no unique index on it, so one row per warehouse.
                'stocks' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'quantity', 'type' => $overrides['quantity_type'] ?? 'int', 'numeric' => ($overrides['quantity_type'] ?? 'int') === 'int'],
                ],
                // A name and nothing else a catalog needs. "ТехноИмпорт" is not
                // a product.
                'suppliers' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'phone', 'type' => 'varchar', 'numeric' => false],
                ],
                // Ditto. "Иван Петров" is not a product either.
                'customers' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'email', 'type' => 'varchar', 'numeric' => false],
                ],
                'orders' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'customer_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'quantity', 'type' => 'int', 'numeric' => true],
                    ['name' => 'total', 'type' => 'decimal', 'numeric' => true],
                ],
            ],
            keys: [
                ['table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
                ['table' => 'products', 'column' => 'supplier_id', 'foreign_table' => 'suppliers', 'foreign_column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id', 'foreign_table' => 'products', 'foreign_column' => 'id'],
                ['table' => 'orders', 'column' => 'customer_id', 'foreign_table' => 'customers', 'foreign_column' => 'id'],
                ['table' => 'orders', 'column' => 'product_id', 'foreign_table' => 'products', 'foreign_column' => 'id'],
            ],
            counts: ['products' => 4100, 'customers' => 900, 'orders' => 26000],
            // Primary keys only. `stocks.product_id` deliberately absent — that
            // is the entire premise.
            unique: [
                ['table' => 'products', 'column' => 'id'],
                ['table' => 'categories', 'column' => 'id'],
                ['table' => 'stocks', 'column' => 'id'],
                ['table' => 'suppliers', 'column' => 'id'],
                ['table' => 'customers', 'column' => 'id'],
                ['table' => 'orders', 'column' => 'id'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function discover(array $overrides = []): DiscoveryResult
    {
        return app(SchemaDiscoverer::class)->discover($this->driver($overrides));
    }

    protected function products(): DiscoveryProposal
    {
        $best = $this->discover()->best();

        $this->assertNotNull($best);
        $this->assertSame('products', $best->table);

        return $best;
    }

    // ---- Issue 1: the stock table with a row per warehouse -----------------

    public function test_stock_is_read_from_a_table_with_no_unique_key_by_summing_it(): void
    {
        $best = $this->products();

        // The join that used to be refused outright. It is still refused as a
        // PLAIN join — that would return a product once per warehouse — and
        // offered as a folded one, which cannot.
        $this->assertTrue($best->joins->supplies('stock'));
        $this->assertArrayHasKey('stock', $best->columns());

        $stock = null;
        foreach ($best->joins->joins as $join) {
            if ($join->table === 'stocks') {
                $stock = $join;
            }
        }

        $this->assertNotNull($stock);
        $this->assertSame('sum', $stock->aggregate);
        $this->assertSame(['stock' => 'quantity'], $stock->columns);
        $this->assertTrue($stock->isDirect());

        // The owner is told the number is a total rather than left to discover
        // it from a customer complaint.
        $summary = collect($best->joinSummary())->firstWhere('field', 'stock');
        $this->assertSame('stocks', $summary['table']);
        $this->assertTrue($summary['aggregated']);

        // And nothing is reported as missing, because nothing is.
        $this->assertSame([], $best->notes);
    }

    public function test_the_summed_join_survives_the_same_validation_an_ordinary_plan_does(): void
    {
        $result = $this->discover();
        $best = $result->best();

        // The closed-set check is not relaxed for aggregated joins: the plan is
        // validated against the discovered schema exactly as before, and it is
        // this check that ProductQuery re-runs before every customer search.
        $this->assertTrue($best->joins->isValid($result->schema, 'products'));
    }

    public function test_the_summed_join_compiles_to_one_grouped_select_per_engine(): void
    {
        $result = $this->discover();
        $best = $result->best();

        $query = new ProductQuery(
            table: 'products',
            columns: $best->columns(),
            limit: 20,
            joins: $best->joins,
            schema: $result->schema,
        );

        foreach ([$this->mysql(), $this->postgres()] as $driver) {
            [$sql, $bindings] = $driver->compile($query);

            $quote = $driver instanceof MySqlDriver ? '`' : '"';
            $q = fn (string $name) => $quote.$name.$quote;

            // One row per product, guaranteed by the grouping rather than by a
            // unique index the shop does not have.
            $this->assertStringContainsString(
                'LEFT JOIN (SELECT '.$q('product_id').', SUM('.$q('quantity').') AS '.$q('quantity')
                .' FROM '.$q('stocks').' GROUP BY '.$q('product_id').')',
                $sql,
            );

            // Everything the single-table search guaranteed still holds.
            $this->assertStringStartsWith('SELECT', $sql);
            $this->assertStringNotContainsString(';', $sql);
            $this->assertStringEndsWith(' LIMIT 20', $sql);
            $this->assertSame([], $bindings);
        }
    }

    public function test_a_filter_on_the_summed_stock_is_still_a_bound_parameter(): void
    {
        $result = $this->discover();
        $best = $result->best();
        $columns = $best->columns();

        $query = new ProductQuery(
            table: 'products',
            columns: $columns,
            conditions: [['column' => $columns['stock'], 'operator' => '>', 'value' => 0]],
            limit: 20,
            joins: $best->joins,
            schema: $result->schema,
        );

        [$sql, $bindings] = $this->mysql()->compile($query);

        $this->assertStringContainsString('WHERE `j1`.`quantity` > ?', $sql);
        $this->assertSame([0], $bindings);
    }

    public function test_a_stock_table_that_cannot_be_summed_is_reported_rather_than_silently_absent(): void
    {
        // The same schema with the quantity stored as text ("в наличии"). It
        // cannot be added up, so it is not joined — and THAT is what has to be
        // said out loud, because a proposal with no stock column is otherwise
        // indistinguishable from a shop that keeps no stock.
        $best = $this->discover(['quantity_type' => 'varchar'])->best();

        $this->assertFalse($best->joins->supplies('stock'));
        $this->assertArrayNotHasKey('stock', $best->columns());

        $this->assertSame(
            [['code' => 'stock_table_not_numeric', 'params' => ['table' => 'stocks', 'column' => 'quantity']]],
            $best->notes,
        );

        // It reaches the browser, or the owner never sees it.
        $this->assertSame($best->notes, $best->toArray()['notes']);
    }

    public function test_the_orders_table_is_never_summed_into_stock(): void
    {
        // `orders` references products and has a quantity, so on shape alone it
        // is indistinguishable from the stock table. Summing it would tell a
        // customer that a sold-out product has 240 in the warehouse.
        $best = $this->products();

        $this->assertNotContains('orders', $best->joins->tables());

        foreach ($best->joins->joins as $join) {
            $this->assertNotSame('orders', $join->table);
        }
    }

    public function test_a_confirmed_summed_plan_survives_being_stored_and_replayed(): void
    {
        // The plan is validated again on every customer search, against the
        // snapshot rather than against a fresh scan. An aggregated join has to
        // survive that round trip, or the shop silently loses its stock column
        // the first time somebody asks a question.
        $driver = $this->driver(['rows' => [
            ['name' => 'Куртка зимняя', 'price' => '4500.00', 'category' => 'Верхняя одежда', 'stock' => 7],
        ]]);

        app(DriverManager::class)->register('mysql', 3306, 'MySQL / MariaDB', fn () => $driver);

        $agent = Agent::factory()->for(User::factory())->create();
        DatabaseConnection::factory()->for($agent)->create(['status' => DatabaseConnection::STATUS_READY]);

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.discovery.confirm', $agent), ['table' => 'products'])
            ->assertOk();

        $connection = $agent->databaseConnection()->first();

        $stock = collect($connection->joins)->firstWhere('table', 'stocks');
        $this->assertSame('sum', $stock['aggregate']);

        // The snapshot keeps the stocks table, which is what the replayed
        // validation checks the folded column's type against.
        $this->assertTrue($connection->discoveredSchema()->isNumeric('stocks', 'quantity'));
        $this->assertTrue($connection->joinPlan()->isValid($connection->discoveredSchema(), 'products'));

        $agent->forceFill(['product_source' => 'database'])->save();

        app(ProductSearchService::class)->search($agent->refresh(), ['in_stock_only' => true]);

        $query = $driver->lastQuery();

        $this->assertNotNull($query);
        $this->assertTrue($query->isJoined());
        $this->assertStringContainsString(
            'SUM(`quantity`) AS `quantity`',
            $this->mysql()->compile($query)[0],
        );
    }

    // ---- Issue 2: tables that are not catalogs -----------------------------

    public function test_customers_and_suppliers_are_not_offered_as_product_tables(): void
    {
        $result = $this->discover();

        // Not "offered at low confidence" — not offered. Their only product-ish
        // signal is a name column, and neither has a price nor any safe hop to
        // one, which is what separates a catalog from a list of people.
        $this->assertNull($result->proposalFor('customers'));
        $this->assertNull($result->proposalFor('suppliers'));

        $this->assertSame(['products'], array_map(
            fn (DiscoveryProposal $proposal) => $proposal->table,
            $result->proposals,
        ));
    }

    public function test_the_one_table_that_is_a_catalog_is_still_found_confidently(): void
    {
        $best = $this->products();

        // Tightening the floor must not cost the answer it exists to protect.
        $this->assertSame(TableCandidate::HIGH, $best->confidence);
        $this->assertSame('name', $best->roles->column('name'));
        $this->assertSame('price', $best->roles->column('price'));
        $this->assertSame(
            ['name', 'price', 'category', 'stock'],
            array_keys($best->columns()),
        );
    }

    public function test_a_table_with_a_price_one_safe_hop_away_is_still_a_candidate(): void
    {
        // The floor is "a price, here or one safe hop away" — not "a price
        // column". OpenCart keeps the name and description on a companion table
        // and the price on the product row, and that table stays admissible.
        $result = app(SchemaDiscoverer::class)->discover(new FakeDatabaseDriver(
            tables: [
                ['name' => 'oc_product', 'type' => 'table'],
                ['name' => 'oc_product_description', 'type' => 'table'],
            ],
            schema: [
                'oc_product' => [
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                    ['name' => 'quantity', 'type' => 'int', 'numeric' => true],
                ],
                'oc_product_description' => [
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'description', 'type' => 'text', 'numeric' => false],
                ],
            ],
            keys: [
                ['table' => 'oc_product_description', 'column' => 'product_id', 'foreign_table' => 'oc_product', 'foreign_column' => 'product_id'],
            ],
            // Composite primary key on the description table, as OpenCart really
            // has it: `product_id` alone is not unique.
            unique: [['table' => 'oc_product', 'column' => 'product_id']],
        ));

        $description = $result->proposalFor('oc_product_description');

        $this->assertNotNull($description);
        $this->assertTrue($description->joins->supplies('price'));
    }

    protected function mysql(): MySqlDriver
    {
        return new MySqlDriver(new ConnectionConfig('mysql', 'db.example.com', 3306, 'shop', 'readonly', 'secret'));
    }

    protected function postgres(): PostgresDriver
    {
        return new PostgresDriver(new ConnectionConfig('pgsql', 'db.example.com', 5432, 'shop', 'readonly', 'secret'));
    }
}
