<?php

namespace Tests\Feature\Database;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Database\DriverManager;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * search_products against a catalog spread over several tables.
 *
 * The composed query is built by the platform from a plan the owner confirmed —
 * the model still fills in the same seven parameters and still never names a
 * table. What is asserted here is that widening the query did not widen what the
 * model can reach, and that a plan which no longer fits the schema costs the
 * shop a column rather than its answers.
 */
class JoinedProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected FakeDatabaseDriver $driver;

    protected ProductSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDatabaseDriver(
            tables: [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'stocks', 'type' => 'table'],
            ],
            rows: [
                ['name' => 'Куртка зимняя', 'price' => '4500.00', 'stock' => 3, 'category' => 'Верхняя одежда'],
                ['name' => 'Шапка вязаная', 'price' => '450.00', 'stock' => 0, 'category' => 'Аксессуары'],
            ],
        );

        app(DriverManager::class)->register('mysql', 3306, 'MySQL / MariaDB', fn () => $this->driver);

        $this->search = app(ProductSearchService::class);
    }

    protected function joinedAgent(): Agent
    {
        $agent = Agent::factory()->for(User::factory())->create();
        DatabaseConnection::factory()->for($agent)->joined()->create();
        $agent->forceFill(['product_source' => 'database'])->save();

        return $agent->refresh();
    }

    public function test_category_and_stock_are_read_from_their_own_tables(): void
    {
        $agent = $this->joinedAgent();

        $result = $this->search->search($agent, ['query' => 'куртка']);

        $query = $this->driver->lastQuery();
        $this->assertNotNull($query);
        $this->assertTrue($query->isJoined());
        $this->assertSame('products', $query->table);

        // The product's own columns stay on the product table...
        $this->assertSame('title', $query->columns['name']->column);
        $this->assertTrue($query->columns['name']->isBase());

        // ...and the fields the plan supplies come from their own tables.
        $this->assertSame('name', $query->columns['category']->column);
        $this->assertSame('j0', $query->columns['category']->alias);
        $this->assertSame('quantity', $query->columns['stock']->column);
        $this->assertSame('j1', $query->columns['stock']->alias);

        // The agent sees exactly what it sees from a spreadsheet.
        $this->assertSame('Куртка зимняя', $result['products'][0]['name']);
        $this->assertSame('Верхняя одежда', $result['products'][0]['category']);
        $this->assertTrue($result['products'][0]['in_stock']);
    }

    public function test_a_filter_on_a_joined_column_is_applied_to_that_table(): void
    {
        $agent = $this->joinedAgent();

        $this->search->search($agent, ['in_stock_only' => true, 'category' => 'аксессуары']);

        $query = $this->driver->lastQuery();

        // The stock condition names the stocks table, and its numeric-ness was
        // judged from the snapshot rather than from the product table.
        $this->assertCount(1, $query->conditions);
        $this->assertSame('quantity', $query->conditions[0]['column']->column);
        $this->assertSame('j1', $query->conditions[0]['column']->alias);
        $this->assertSame('>', $query->conditions[0]['operator']);

        $this->assertSame('name', $query->textGroups[0]['columns'][0]->column);
        $this->assertSame('j0', $query->textGroups[0]['columns'][0]->alias);
    }

    public function test_the_row_cap_still_applies_to_a_joined_search(): void
    {
        $agent = $this->joinedAgent();

        $result = $this->search->search($agent, ['limit' => 500]);

        $this->assertSame(ProductSearchService::MAX_LIMIT, $this->driver->lastQuery()->limit);
        $this->assertSame(ProductSearchService::MAX_LIMIT, $result['limit']);
    }

    public function test_the_product_tables_own_column_wins_over_a_joined_one(): void
    {
        $agent = $this->joinedAgent();
        $connection = $agent->databaseConnection()->first();

        // The owner mapped a category column on the product table itself. That
        // is what they confirmed, so that is what is read.
        $connection->forceFill([
            'schema_columns' => [
                ...$connection->schema_columns,
                ['name' => 'cat_text', 'type' => 'varchar', 'numeric' => false],
            ],
            'mapping' => ['name' => 'title', 'price' => 'price', 'category' => 'cat_text'],
        ])->save();

        $this->search->search($agent->refresh(), ['query' => 'куртка']);

        $category = $this->driver->lastQuery()->columns['category'];
        $this->assertSame('cat_text', $category->column);
        $this->assertTrue($category->isBase());
    }

    public function test_a_plan_that_no_longer_fits_the_schema_costs_a_column_not_the_answers(): void
    {
        Log::spy();

        $agent = $this->joinedAgent();
        $connection = $agent->databaseConnection()->first();

        // The shop drops the unique key on stocks.product_id: the join would now
        // return a product once per stock row.
        $snapshot = $connection->schema_snapshot;
        $snapshot['unique_columns'] = ['products.id', 'categories.id'];
        $connection->forceFill(['schema_snapshot' => $snapshot])->save();

        $result = $this->search->search($agent->refresh(), ['query' => 'куртка']);

        $query = $this->driver->lastQuery();

        // The whole plan is dropped rather than partly applied — a plan is
        // confirmed as a unit, and half of one is not something the owner saw.
        $this->assertFalse($query->isJoined());
        $this->assertArrayNotHasKey('category', $query->columns);
        $this->assertArrayNotHasKey('stock', $query->columns);

        // The customer still gets an answer from the product table.
        $this->assertSame('products', $query->table);
        $this->assertSame(2, $result['count']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'join plan no longer matches'))
            ->once();
    }

    public function test_a_connection_with_no_snapshot_falls_back_to_the_single_table_query(): void
    {
        $agent = $this->joinedAgent();

        // A connection set up entirely by hand has joins from nowhere and no
        // snapshot to check them against.
        $agent->databaseConnection()->first()->forceFill(['schema_snapshot' => null])->save();

        $this->search->search($agent->refresh(), ['query' => 'куртка']);

        $this->assertFalse($this->driver->lastQuery()->isJoined());
    }

    public function test_a_joined_connection_is_still_reached_only_through_its_own_agent(): void
    {
        $mine = $this->joinedAgent();
        $theirs = $this->joinedAgent();

        $theirs->databaseConnection()->first()->forceFill([
            'table_name' => 'their_products',
            'schema_snapshot' => null,
            'joins' => null,
        ])->save();

        $this->search->search($mine, ['query' => 'куртка']);

        // The connection is loaded by agent_id off the agent the reply pipeline
        // is holding; there is no id in the tool's parameters for anything to
        // point at another tenant's tables.
        $this->assertSame('products', $this->driver->lastQuery()->table);
        $this->assertTrue($this->driver->lastQuery()->isJoined());

        $this->search->search($theirs->refresh(), ['query' => 'куртка']);
        $this->assertSame('their_products', $this->driver->lastQuery()->table);
        $this->assertFalse($this->driver->lastQuery()->isJoined());
    }
}
