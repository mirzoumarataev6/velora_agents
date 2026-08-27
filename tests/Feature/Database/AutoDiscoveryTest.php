<?php

namespace Tests\Feature\Database;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * The owner-facing flow: connect, press one button, review real rows, confirm.
 *
 * The rules this holds to are the ones that make the guess safe to offer at all:
 * nothing is written until the owner confirms, the confirm step recomputes what
 * it stores rather than believing the browser, and the manual flow underneath is
 * still there and still works.
 */
class AutoDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected FakeDatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDatabaseDriver(
            tables: [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'stocks', 'type' => 'table'],
                ['name' => 'orders', 'type' => 'table'],
                ['name' => 'users', 'type' => 'table'],
            ],
            rows: [
                ['name' => 'Куртка зимняя', 'price' => '4500.00', 'category' => 'Верхняя одежда', 'stock' => 3],
                ['name' => 'Шапка вязаная', 'price' => '450.00', 'category' => 'Аксессуары', 'stock' => 0],
            ],
            schema: [
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
                'users' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'email', 'type' => 'varchar', 'numeric' => false],
                ],
            ],
            keys: [
                ['table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id', 'foreign_table' => 'products', 'foreign_column' => 'id'],
            ],
            counts: ['products' => 3200],
            unique: [
                ['table' => 'products', 'column' => 'id'],
                ['table' => 'categories', 'column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id'],
                ['table' => 'orders', 'column' => 'id'],
                ['table' => 'users', 'column' => 'id'],
            ],
        );

        app(DriverManager::class)->register('mysql', 3306, 'MySQL / MariaDB', fn () => $this->driver);
    }

    protected function connected(): Agent
    {
        $agent = Agent::factory()->for(User::factory())->create();
        DatabaseConnection::factory()->for($agent)->create(['status' => DatabaseConnection::STATUS_READY]);

        return $agent;
    }

    public function test_discovery_proposes_the_catalog_with_real_rows_and_saves_nothing(): void
    {
        $agent = $this->connected();

        $response = $this->actingAs($agent->user)
            ->postJson(route('agents.database.discover', $agent))
            ->assertOk()
            ->assertJsonPath('proposals.0.table', 'products')
            ->assertJsonPath('proposals.0.confidence', 'high')
            ->assertJsonPath('ambiguous', false)
            ->assertJsonPath('method', 'deterministic');

        // Composed across three tables without the owner writing a VIEW.
        $this->assertSame(
            [['field' => 'category', 'table' => 'categories', 'declared' => true, 'aggregated' => false],
                ['field' => 'stock', 'table' => 'stocks', 'declared' => true, 'aggregated' => false]],
            $response->json('proposals.0.join_summary'),
        );

        // Real rows, as the agent would see them.
        $this->assertSame('Куртка зимняя', $response->json('proposals.0.preview.0.name'));
        $this->assertSame('Верхняя одежда', $response->json('proposals.0.preview.0.category'));

        // And nothing has been written: this is a proposal, not a decision.
        $connection = $agent->databaseConnection()->first();
        $this->assertNull($connection->table_name);
        $this->assertNull($connection->mapping);
        $this->assertNull($connection->joins);
    }

    public function test_the_reasons_are_returned_so_the_owner_can_check_the_guess(): void
    {
        $agent = $this->connected();

        $codes = array_column(
            $this->actingAs($agent->user)
                ->postJson(route('agents.database.discover', $agent))
                ->json('proposals.0.reasons'),
            'code',
        );

        $this->assertContains('table_name_matches', $codes);
        $this->assertContains('has_name_column', $codes);
        $this->assertContains('linked_to_categories', $codes);

        // Every code has a sentence behind it in each language the tab renders.
        foreach ($codes as $code) {
            $this->assertNotSame(
                "agents.database.discovery.reasons.{$code}",
                __("agents.database.discovery.reasons.{$code}"),
                "Missing translation for reason [{$code}].",
            );
        }
    }

    public function test_orders_and_user_tables_are_never_proposed(): void
    {
        $agent = $this->connected();

        $tables = array_column(
            $this->actingAs($agent->user)->postJson(route('agents.database.discover', $agent))->json('proposals'),
            'table',
        );

        $this->assertSame(['products'], $tables);
    }

    public function test_confirming_stores_the_mapping_the_joins_and_the_schema_it_was_checked_against(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.discovery.confirm', $agent), ['table' => 'products'])
            ->assertOk()
            ->assertJsonPath('connection.table', 'products')
            ->assertJsonPath('connection.usable', true)
            ->assertJsonPath('connection.confidence', 'high')
            ->assertJsonPath('connection.method', 'deterministic');

        $connection = $agent->databaseConnection()->first();

        $this->assertSame('products', $connection->table_name);
        $this->assertSame(['name' => 'Название', 'price' => 'Цена'], $connection->mapping);

        $plan = $connection->joinPlan();
        $this->assertCount(2, $plan->joins);
        $this->assertSame(['categories', 'stocks'], $plan->tables());

        // The snapshot is the closed set the search validates against, and holds
        // only the tables this plan actually reads.
        $schema = $connection->discoveredSchema();
        $this->assertNotNull($schema);
        $this->assertEqualsCanonicalizing(['products', 'categories', 'stocks'], $schema->tableNames());
        $this->assertFalse($schema->hasTable('users'));
        $this->assertTrue($plan->isValid($schema, 'products'));

        $this->assertNotNull($connection->discovered_at);
    }

    public function test_confirming_a_table_that_was_not_proposed_is_refused(): void
    {
        $agent = $this->connected();

        // A real table, and not one of the proposals. The endpoint recomputes
        // the shortlist rather than trusting the request, so this is refused
        // even though the table exists.
        $this->actingAs($agent->user)
            ->putJson(route('agents.database.discovery.confirm', $agent), ['table' => 'users'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.database.errors.unknown_table'));

        $this->assertNull($agent->databaseConnection()->first()->table_name);
    }

    public function test_the_mapping_and_joins_are_recomputed_rather_than_taken_from_the_request(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.discovery.confirm', $agent), [
                'table' => 'products',
                // A hand-made request cannot smuggle in a mapping the owner
                // never saw, nor a join to a table nobody proposed.
                'mapping' => ['name' => 'email'],
                'joins' => [['table' => 'users', 'alias' => 'j0', 'local_alias' => '', 'local_column' => 'id', 'foreign_column' => 'id', 'columns' => ['name' => 'email']]],
            ])
            ->assertOk();

        $connection = $agent->databaseConnection()->first();

        $this->assertSame(['name' => 'Название', 'price' => 'Цена'], $connection->mapping);
        $this->assertNotContains('users', $connection->joinPlan()->tables());
    }

    public function test_two_plausible_catalogs_are_both_offered_and_the_owner_picks(): void
    {
        $agent = $this->connected();

        $this->driver->tables = [
            ['name' => 'products_retail', 'type' => 'table'],
            ['name' => 'products_wholesale', 'type' => 'table'],
        ];
        $this->driver->schema = [
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
        ];
        $this->driver->keys = [];
        $this->driver->unique = [
            ['table' => 'products_retail', 'column' => 'id'],
            ['table' => 'products_wholesale', 'column' => 'id'],
        ];

        $response = $this->actingAs($agent->user)
            ->postJson(route('agents.database.discover', $agent))
            ->assertOk()
            ->assertJsonPath('ambiguous', true);

        // Quoting wholesale prices to retail customers is what a silent pick
        // would cause, so both go to the owner.
        $this->assertCount(2, $response->json('proposals'));

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.discovery.confirm', $agent), ['table' => 'products_wholesale'])
            ->assertOk();

        $this->assertSame('products_wholesale', $agent->databaseConnection()->first()->table_name);
    }

    public function test_a_schema_with_nothing_recognizable_proposes_nothing_rather_than_guessing(): void
    {
        $agent = $this->connected();

        $this->driver->tables = [['name' => 'sessions', 'type' => 'table']];
        $this->driver->schema = ['sessions' => [['name' => 'payload', 'type' => 'text', 'numeric' => false]]];
        $this->driver->keys = [];
        $this->driver->unique = [];

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.discover', $agent))
            ->assertOk()
            ->assertJsonPath('proposals', []);
    }

    public function test_a_database_with_too_many_tables_says_so_and_leaves_the_manual_flow_working(): void
    {
        config(['agent.database.discovery.max_tables' => 3]);

        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.discover', $agent))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'schema_too_large');

        // The picker still lists them: automatic discovery declining is not the
        // connection failing.
        $this->actingAs($agent->user)
            ->getJson(route('agents.database.tables', $agent))
            ->assertOk()
            ->assertJsonPath('tables.0.name', 'products');
    }

    public function test_an_unreachable_database_reports_it_and_marks_the_connection(): void
    {
        $agent = $this->connected();

        $this->driver->failWith = new DatabaseConnectionException(DatabaseConnectionException::UNREACHABLE);

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.discover', $agent))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unreachable');

        $this->assertSame(DatabaseConnection::STATUS_ERROR, $agent->databaseConnection()->first()->status);
    }

    public function test_discovery_needs_a_saved_connection(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.discover', $agent))
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.database.errors.not_connected'));
    }

    public function test_discovery_is_scoped_to_the_owner(): void
    {
        $agent = $this->connected();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('agents.database.discover', $agent))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->putJson(route('agents.database.discovery.confirm', $agent), ['table' => 'products'])
            ->assertForbidden();

        $this->assertNull($agent->databaseConnection()->first()->table_name);
    }

    public function test_the_manual_flow_still_overrides_whatever_discovery_decided(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.discovery.confirm', $agent), ['table' => 'products'])
            ->assertOk();

        // The advanced panel is the fallback, and it still wins.
        $this->actingAs($agent->user)
            ->putJson(route('agents.database.mapping', $agent), [
                'table' => 'products',
                'mapping' => ['name' => 'Название'],
            ])
            ->assertOk();

        $connection = $agent->databaseConnection()->first();
        $this->assertSame(['name' => 'Название'], $connection->mapping);
        $this->assertTrue($connection->isUsable());
    }
}
