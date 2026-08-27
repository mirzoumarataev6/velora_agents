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
 * Reading the customer's schema, choosing a table, and mapping its columns.
 *
 * The mapping is the only place a column name enters the system, so most of
 * what is asserted here is what CANNOT be saved.
 */
class SchemaDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected FakeDatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDatabaseDriver(
            tables: [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'v_catalog', 'type' => 'view'],
                ['name' => 'users', 'type' => 'table'],
            ],
            columns: [
                ['name' => 'Название', 'type' => 'varchar', 'numeric' => false],
                ['name' => 'Цена', 'type' => 'decimal', 'numeric' => true],
                ['name' => 'Остаток', 'type' => 'int', 'numeric' => true],
                ['name' => 'internal_notes', 'type' => 'text', 'numeric' => false],
            ],
            sample: [
                ['Название' => 'Куртка', 'Цена' => '4500.00', 'Остаток' => 3, 'internal_notes' => str_repeat('x', 500)],
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

    public function test_tables_and_views_are_listed(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->getJson(route('agents.database.tables', $agent))
            ->assertOk()
            ->assertJsonPath('tables.0.name', 'products')
            ->assertJsonPath('tables.1.type', 'view');
    }

    public function test_discovery_needs_a_saved_connection(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();

        $this->actingAs($agent->user)
            ->getJson(route('agents.database.tables', $agent))
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.database.errors.not_connected'));
    }

    public function test_describing_a_table_returns_columns_samples_and_a_suggested_mapping(): void
    {
        $agent = $this->connected();

        $response = $this->actingAs($agent->user)
            ->postJson(route('agents.database.describe', $agent), ['table' => 'products']);

        $response->assertOk()
            ->assertJsonPath('columns.1.numeric', true)
            // The catalog's own ColumnMapper vocabulary, reused: the same header
            // names an owner already mapped for a spreadsheet are guessed here.
            ->assertJsonPath('mapping.name', 'Название')
            ->assertJsonPath('mapping.price', 'Цена')
            ->assertJsonPath('mapping.stock', 'Остаток');

        // Sample values are trimmed: a product table may hold a 50KB description.
        $this->assertLessThanOrEqual(80, mb_strlen($response->json('sample.0.internal_notes')));
    }

    public function test_a_table_the_server_did_not_report_cannot_be_described(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.describe', $agent), ['table' => 'information_schema.tables'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.database.errors.unknown_table'));
    }

    public function test_the_mapping_is_persisted_with_the_schema_it_was_confirmed_against(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.mapping', $agent), [
                'table' => 'products',
                'mapping' => ['name' => 'Название', 'price' => 'Цена', 'stock' => 'Остаток', 'sku' => null],
            ])
            ->assertOk()
            ->assertJsonPath('connection.table', 'products')
            ->assertJsonPath('connection.usable', true);

        $connection = $agent->databaseConnection()->first();

        $this->assertSame('products', $connection->table_name);
        $this->assertSame('table', $connection->table_type);
        // Unassigned fields are dropped rather than stored as null.
        $this->assertSame(['name' => 'Название', 'price' => 'Цена', 'stock' => 'Остаток'], $connection->mapping);
        // The snapshot the search will validate identifiers against.
        $this->assertSame(['Название', 'Цена', 'Остаток', 'internal_notes'], array_column($connection->schema_columns, 'name'));
        $this->assertNotNull($connection->discovered_at);
    }

    public function test_a_column_that_is_not_in_the_table_cannot_be_mapped(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.mapping', $agent), [
                'table' => 'products',
                'mapping' => ['name' => 'Название', 'price' => '(SELECT password FROM users)'],
            ])
            ->assertStatus(422);

        $this->assertNull($agent->databaseConnection()->first()->table_name);
    }

    public function test_a_mapping_key_outside_the_vocabulary_is_discarded(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.mapping', $agent), [
                'table' => 'products',
                'mapping' => ['name' => 'Название', 'secret' => 'internal_notes'],
            ])
            ->assertOk();

        $this->assertSame(['name' => 'Название'], $agent->databaseConnection()->first()->mapping);
    }

    public function test_the_product_name_column_is_required(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.mapping', $agent), [
                'table' => 'products',
                'mapping' => ['price' => 'Цена'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mapping.name');
    }

    public function test_rediscovery_replaces_the_stored_schema(): void
    {
        $agent = $this->connected();

        $this->actingAs($agent->user)->putJson(route('agents.database.mapping', $agent), [
            'table' => 'products',
            'mapping' => ['name' => 'Название'],
        ])->assertOk();

        // The shop renames a column between one discovery and the next.
        $this->driver->columns = [['name' => 'title', 'type' => 'varchar', 'numeric' => false]];

        $this->actingAs($agent->user)->putJson(route('agents.database.mapping', $agent), [
            'table' => 'products',
            'mapping' => ['name' => 'title'],
        ])->assertOk();

        $connection = $agent->databaseConnection()->first();
        $this->assertSame(['title'], array_column($connection->schema_columns, 'name'));
        $this->assertSame(['name' => 'title'], $connection->mapping);
    }

    public function test_a_database_that_goes_away_during_discovery_reports_it_and_marks_the_connection(): void
    {
        $agent = $this->connected();

        $this->driver->failWith = new DatabaseConnectionException(DatabaseConnectionException::UNREACHABLE);

        $this->actingAs($agent->user)
            ->getJson(route('agents.database.tables', $agent))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unreachable');

        // A failure using the STORED credentials does say something about the
        // stored connection, so it is recorded for the tab to show.
        $this->assertSame(DatabaseConnection::STATUS_ERROR, $agent->databaseConnection()->first()->status);
    }

    public function test_discovery_is_scoped_to_the_owner(): void
    {
        $agent = $this->connected();

        $this->actingAs(User::factory()->create())
            ->postJson(route('agents.database.describe', $agent), ['table' => 'products'])
            ->assertForbidden();
    }
}
