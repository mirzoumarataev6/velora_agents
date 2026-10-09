<?php

namespace Tests\Feature\Bi;

use App\Models\Agent;
use App\Models\BiConnection;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DropsAnalyticalTables;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * THE REGRESSION TEST FOR THE DECOUPLING.
 *
 * The BI module used to borrow an agent's `DatabaseConnection`. It does not any
 * more, and this file exists to make sure it never does again — because the
 * coupling was reintroduced easily and failed quietly: the symptom was a
 * dashboard that went blank weeks later, when an owner deleted an assistant
 * they no longer used.
 *
 * Four properties, each failing loudly if someone wires the two back together:
 *
 *  1. An agent's connection id is NOT accepted by any BI endpoint — not as a
 *     different model that happens to share an integer, not at all.
 *  2. `bi_sources` has no column pointing at `database_connections`.
 *  3. Deleting an agent — and with it its connection — leaves BI untouched.
 *  4. The two tables hold genuinely separate credentials, even when they
 *     describe the same physical server.
 */
class BiConnectionIndependenceTest extends TestCase
{
    use DropsAnalyticalTables, RefreshDatabase;

    private User $user;

    private FakeDatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->driver = new FakeDatabaseDriver(
            tables: [['name' => 'orders', 'type' => 'table']],
            schema: ['orders' => [
                ['name' => 'id', 'type' => 'int', 'numeric' => true],
                ['name' => 'total', 'type' => 'decimal(12,2)', 'numeric' => true],
            ]],
            sample: [['id' => 1, 'total' => '10.00']],
        );

        app(DriverManager::class)->register('mysql', 3306, 'MySQL / MariaDB', fn () => $this->driver);
    }

    /** An agent of this owner, with a verified product-database connection. */
    private function agentConnection(): DatabaseConnection
    {
        $agent = Agent::factory()->create(['user_id' => $this->user->id]);

        return DatabaseConnection::factory()->configured()->create(['agent_id' => $agent->id]);
    }

    // --- 1. An agent's connection id is not accepted anywhere in BI ----------

    /**
     * The exact shape of the old coupling, attempted on purpose.
     *
     * The ids are forced apart first, so this cannot pass by accident: if both
     * tables started at 1, posting the agent connection's id would find the
     * owner's own BI connection and succeed for the wrong reason.
     */
    public function test_an_agents_connection_id_is_never_accepted_when_registering(): void
    {
        $agentConnection = $this->agentConnection();

        // Make sure the id cannot collide with any BI connection.
        $agentConnection->forceFill(['id' => 4242])->saveQuietly();

        $this->actingAs($this->user)
            ->postJson(route('bi.connections.sources'), [
                'bi_connection_id' => $agentConnection->id,
                'tables' => ['orders'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bi_connection_id');

        $this->assertSame(0, BiSource::count());
    }

    public function test_an_agents_connection_cannot_be_read_through_the_bi_tables_endpoint(): void
    {
        $agentConnection = $this->agentConnection();
        $agentConnection->forceFill(['id' => 4242])->saveQuietly();

        // Route-model binding resolves `{connection}` as a BiConnection, so an
        // id that exists only in `database_connections` is simply not found.
        $this->actingAs($this->user)
            ->getJson(route('bi.connections.tables', $agentConnection->id))
            ->assertNotFound();
    }

    /**
     * The BI picker never mentions an agent's connection, even when the owner
     * has one and has none of their own.
     */
    public function test_the_bi_picker_does_not_list_agent_connections(): void
    {
        $this->agentConnection();

        $this->actingAs($this->user)
            ->getJson(route('bi.connections.index'))
            ->assertOk()
            ->assertJsonPath('connections', []);
    }

    // --- 2. No column, no foreign key ----------------------------------------

    public function test_bi_sources_has_no_column_pointing_at_the_agents_table(): void
    {
        $this->assertFalse(
            Schema::hasColumn('bi_sources', 'database_connection_id'),
            'A BI source must not reference an agent-owned connection.'
        );

        $this->assertTrue(Schema::hasColumn('bi_sources', 'bi_connection_id'));
    }

    public function test_the_bi_source_model_exposes_no_agent_connection_relation(): void
    {
        $this->assertFalse(
            method_exists(BiSource::class, 'databaseConnection'),
            'The relation into the agents\' connection table must stay removed.'
        );

        $this->assertTrue(method_exists(BiSource::class, 'biConnection'));
    }

    // --- 3. Deleting an agent does not touch BI ------------------------------

    /**
     * THE BUG THE OLD DESIGN ACTUALLY HAD.
     *
     * Deleting an agent cascaded into `database_connections`, which nulled
     * `bi_sources.database_connection_id` — leaving a connection-backed source
     * pointing at nothing, with no error anywhere and no way for the owner to
     * tell what had happened. Here the two are independent, and the BI side is
     * provably untouched.
     */
    public function test_deleting_an_agent_leaves_bi_data_intact(): void
    {
        $agentConnection = $this->agentConnection();
        $agent = $agentConnection->agent;

        $biConnection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $biConnection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $source = BiSource::sole();
        $this->assertSame($biConnection->id, $source->bi_connection_id);

        $agent->delete();

        $this->assertSame(0, DatabaseConnection::count(), 'The agent took its own connection with it.');

        $fresh = $source->fresh();
        $this->assertNotNull($fresh, 'The BI source must survive the agent.');
        $this->assertSame($biConnection->id, $fresh->bi_connection_id);
        $this->assertDatabaseHas('bi_connections', ['id' => $biConnection->id]);

        // And it still works: the dataset is queryable, not orphaned.
        $this->actingAs($this->user)
            ->getJson(route('bi.datasets.show', BiDataset::sole()))
            ->assertOk();
    }

    public function test_deleting_a_bi_connection_leaves_the_agents_connection_intact(): void
    {
        $agentConnection = $this->agentConnection();
        $biConnection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->deleteJson(route('bi.connections.destroy', $biConnection))
            ->assertOk();

        $this->assertDatabaseHas('database_connections', ['id' => $agentConnection->id]);
    }

    // --- 4. Genuinely separate credentials -----------------------------------

    /**
     * The same physical server, connected twice, on purpose.
     *
     * This is the case the user asked about explicitly: an owner may well want
     * to analyse the database their agent already reads. It is allowed, and it
     * produces two rows in two tables with two independently rotatable
     * passwords — not one row referenced twice.
     */
    public function test_the_same_server_connected_for_bi_and_for_an_agent_stays_two_records(): void
    {
        $agentConnection = $this->agentConnection();

        $this->actingAs($this->user)->postJson(route('bi.connections.store'), [
            'name' => 'Те же данные, для анализа',
            'engine' => $agentConnection->engine,
            'host' => $agentConnection->host,
            'port' => $agentConnection->port,
            'database' => $agentConnection->database,
            'username' => $agentConnection->username,
            'password' => 'a-different-readonly-password',
            'ssl' => false,
        ])->assertStatus(201);

        $biConnection = BiConnection::sole();

        // Same server…
        $this->assertSame($agentConnection->host, $biConnection->host);
        $this->assertSame($agentConnection->database, $biConnection->database);

        // …two records, two secrets. Rotating one does not touch the other.
        $this->assertNotSame($agentConnection->password, $biConnection->password);

        $biConnection->update(['password' => 'rotated-again']);

        $this->assertSame(
            $agentConnection->password,
            $agentConnection->fresh()->password,
            'Rotating the BI credential must not disturb the agent\'s.'
        );
    }
}
