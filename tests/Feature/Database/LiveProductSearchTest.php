<?php

namespace Tests\Feature\Database;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\User;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * search_products against a live connection.
 *
 * The tool contract is the thing under test: the agent must behave identically
 * whether the numbers come from a spreadsheet or from the shop's own table, and
 * a database that is down must read to the customer as "I can't check right
 * now", never as an error.
 */
class LiveProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected FakeDatabaseDriver $driver;

    protected ProductSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDatabaseDriver(
            tables: [['name' => 'products', 'type' => 'table']],
            rows: [
                ['name' => 'Куртка зимняя', 'price' => '4500.00', 'stock' => 3, 'category' => 'Верхняя одежда', 'sku' => 'A-1', 'description' => 'Тёплая'],
                ['name' => 'Шапка вязаная', 'price' => '450.00', 'stock' => 0, 'category' => 'Аксессуары', 'sku' => 'A-2', 'description' => 'Шерсть'],
            ],
        );

        app(DriverManager::class)->register('mysql', 3306, 'MySQL / MariaDB', fn () => $this->driver);

        $this->search = app(ProductSearchService::class);
    }

    protected function liveAgent(): Agent
    {
        $agent = Agent::factory()->for(User::factory())->create();
        DatabaseConnection::factory()->for($agent)->configured()->create();
        $agent->forceFill(['product_source' => 'database'])->save();

        return $agent->refresh();
    }

    public function test_the_tool_is_offered_only_when_the_connection_is_fully_configured(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        $agent->forceFill(['product_source' => 'database'])->save();

        // Chosen but nothing connected: no tool at all, rather than a tool that
        // reports an empty shop.
        $this->assertFalse($this->search->enabledFor($agent->refresh()));

        $connection = DatabaseConnection::factory()->for($agent)->create();
        $this->assertFalse($this->search->enabledFor($agent->refresh()));

        // Verified, but no table chosen yet.
        $connection->update(['status' => DatabaseConnection::STATUS_READY]);
        $this->assertFalse($this->search->enabledFor($agent->refresh()));

        $this->assertTrue($this->search->enabledFor($this->liveAgent()));
    }

    public function test_the_tool_definition_is_identical_whatever_the_source(): void
    {
        // The model sees one tool. Its description, parameters and cap must not
        // depend on where the business keeps its products.
        $definition = $this->search->toolDefinition();

        $this->assertSame('search_products', $definition['name']);
        $this->assertSame(
            ['query', 'min_price', 'max_price', 'in_stock_only', 'category', 'sort', 'limit'],
            array_keys($definition['parameters']['properties']),
        );
    }

    public function test_a_search_reaches_the_customers_table_through_the_stored_mapping(): void
    {
        $agent = $this->liveAgent();

        $result = $this->search->search($agent, ['query' => 'куртка', 'max_price' => 5000, 'sort' => 'price_asc']);

        $query = $this->driver->lastQuery();
        $this->assertNotNull($query);
        $this->assertSame('products', $query->table);
        // Column names come from the mapping, never from the model's arguments.
        $this->assertSame('title', $query->columns['name']->column);
        // The category column is searched as text too: a customer's words for a
        // product are as often the shop's category as its product name.
        $this->assertSame(['title', 'cat', 'descr', 'article'], array_column($query->textGroups[0]['columns'], 'column'));
        // Matched on the stem, so the plural the customer typed still finds the
        // singular the shop stored.
        $this->assertSame('куртк', $query->textGroups[0]['value']);
        $this->assertCount(1, $query->conditions);
        $this->assertSame('price', $query->conditions[0]['column']->column);
        $this->assertSame('<=', $query->conditions[0]['operator']);
        $this->assertSame(5000.0, $query->conditions[0]['value']);
        $this->assertSame('price', $query->orderColumn->column);
        // Nothing was joined, so every column belongs to the product table.
        $this->assertFalse($query->isJoined());
        $this->assertTrue($query->columns['name']->isBase());
        $this->assertSame('asc', $query->orderDirection);

        $this->assertSame(2, $result['count']);
        $this->assertSame('Куртка зимняя', $result['products'][0]['name']);
        // Presented the same way an imported product is: no trailing ".00".
        $this->assertSame('4500', $result['products'][0]['price']);
        $this->assertTrue($result['products'][0]['in_stock']);
        $this->assertFalse($result['products'][1]['in_stock']);
    }

    public function test_the_model_cannot_ask_for_more_rows_than_the_cap(): void
    {
        $agent = $this->liveAgent();

        $result = $this->search->search($agent, ['limit' => 500]);

        $this->assertSame(ProductSearchService::MAX_LIMIT, $this->driver->lastQuery()->limit);
        $this->assertSame(ProductSearchService::MAX_LIMIT, $result['limit']);
    }

    public function test_a_wildcard_only_query_matches_nothing_rather_than_everything(): void
    {
        $agent = $this->liveAgent();

        $this->search->search($agent, ['query' => '%']);

        $query = $this->driver->lastQuery();
        $this->assertSame([], $query->textGroups);
        // An impossible condition on the name column, not an unfiltered SELECT.
        $this->assertCount(1, $query->conditions);
        $this->assertSame('title', $query->conditions[0]['column']->column);
    }

    public function test_a_stock_filter_is_dropped_when_the_column_is_not_numeric(): void
    {
        $agent = $this->liveAgent();
        $connection = $agent->databaseConnection()->first();

        // forceFill: the schema and the mapping are deliberately NOT mass
        // assignable — only discovery writes them, and only after re-reading the
        // customer's information_schema.
        $connection->forceFill([
            'schema_columns' => [
                ['name' => 'title', 'type' => 'varchar', 'numeric' => false],
                ['name' => 'qty', 'type' => 'varchar', 'numeric' => false],
            ],
            'mapping' => ['name' => 'title', 'stock' => 'qty'],
        ])->save();

        $this->search->search($agent->refresh(), ['in_stock_only' => true]);

        // "в наличии" cannot be compared to zero; the filter is dropped rather
        // than guessed at, and the model still sees each row's stock value.
        $this->assertSame([], $this->driver->lastQuery()->conditions);
    }

    public function test_a_mapping_left_over_from_a_renamed_column_is_ignored(): void
    {
        $agent = $this->liveAgent();

        $agent->databaseConnection()->first()->forceFill([
            'schema_columns' => [['name' => 'title', 'type' => 'varchar', 'numeric' => false]],
        ])->save();

        $this->search->search($agent->refresh(), ['query' => 'куртка']);

        // price/qty/cat are gone from the schema, so they are not selected and
        // not filtered on — nothing unverified reaches the SQL builder.
        $this->assertSame(
            ['name' => 'title'],
            array_map(fn ($ref) => $ref->column, $this->driver->lastQuery()->columns),
        );
    }

    public function test_an_unreachable_database_degrades_into_a_normal_reply(): void
    {
        $agent = $this->liveAgent();

        $this->driver->failWith = new DatabaseConnectionException(
            DatabaseConnectionException::UNREACHABLE,
            'SQLSTATE[HY000] [2002] Connection refused',
        );

        $result = $this->search->search($agent, ['query' => 'куртка']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['products']);
        $this->assertArrayHasKey('note', $result);
        // Nothing the customer could be shown mentions the failure.
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
        $this->assertStringContainsString('Do not tell the customer', $result['note']);
    }

    public function test_a_connection_belongs_to_one_agent_and_is_never_used_by_another(): void
    {
        $mine = $this->liveAgent();

        $theirs = Agent::factory()->for(User::factory())->create();
        $theirs->forceFill(['product_source' => 'database'])->save();

        // The other tenant has no connection of their own, so there is no source
        // to resolve — mine is never a candidate.
        $this->assertFalse($this->search->enabledFor($theirs->refresh()));

        $result = $this->search->search($theirs->refresh(), ['query' => 'куртка']);

        $this->assertSame(0, $result['count']);
        $this->assertNull($this->driver->lastQuery());
        $this->assertTrue($this->search->enabledFor($mine));
    }

    public function test_the_dataset_still_answers_when_it_is_the_active_source(): void
    {
        $agent = $this->liveAgent();

        $dataset = Dataset::factory()->for($agent)->create();
        DatasetProduct::factory()->create([
            'agent_id' => $agent->id,
            'dataset_id' => $dataset->id,
            'name' => 'Ботинки',
            'price' => 8900,
            'stock' => 2,
        ]);

        $agent->forceFill(['product_source' => 'dataset'])->save();

        $result = $this->search->search($agent->refresh(), ['query' => 'ботинки']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Ботинки', $result['products'][0]['name']);
        // The live connection is configured but not consulted.
        $this->assertNull($this->driver->lastQuery());
    }

    public function test_switching_to_a_half_configured_connection_is_refused(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        DatabaseConnection::factory()->for($agent)->create();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.source', $agent), ['source' => 'database'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.database.errors.not_ready'));

        $this->assertSame('dataset', $agent->refresh()->product_source);
    }

    public function test_switching_sources_is_explicit_and_visible(): void
    {
        $agent = $this->liveAgent();
        $agent->forceFill(['product_source' => 'dataset'])->save();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.source', $agent), ['source' => 'database'])
            ->assertOk()
            ->assertJsonPath('active_source', 'database');

        $this->assertSame('database', $agent->refresh()->product_source);

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.source', $agent), ['source' => 'dataset'])
            ->assertOk()
            ->assertJsonPath('active_source', 'dataset');
    }

    public function test_an_unknown_source_value_is_refused(): void
    {
        $agent = $this->liveAgent();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.source', $agent), ['source' => 'somewhere_else'])
            ->assertStatus(422);

        $this->assertSame('database', $agent->refresh()->product_source);
    }
}
