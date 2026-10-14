<?php

namespace Tests\Feature\Bi;

use App\Models\BiConnection;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DropsAnalyticalTables;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * The BI module's own connections: creating, testing, editing, deleting.
 *
 * Exercised through the same FakeDatabaseDriver the agent-side tests use, so
 * no real host is dialled — which is also what lets the SSRF guard and the
 * read-only session stay exactly as they were. This file is about the RECORD,
 * not the driver.
 */
class BiConnectionTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Склад 1С',
            'engine' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'readonly',
            'password' => 'secret',
            'ssl' => false,
        ], $overrides);
    }

    // --- Creating ------------------------------------------------------------

    public function test_a_connection_is_created_and_owned_by_the_user(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('bi.connections.store'), $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('ok', true);

        $connection = BiConnection::sole();

        $this->assertSame($this->user->id, $connection->user_id);
        $this->assertSame(BiConnection::STATUS_READY, $connection->status);
        $this->assertNotNull($connection->last_tested_at);
    }

    /**
     * NO AGENT IS INVOLVED, anywhere.
     *
     * The point of the whole table: an account with no agents at all can still
     * connect a database for analysis. Under the old design this was impossible
     * — a BI connection had to be borrowed from an agent that had one.
     */
    public function test_an_account_with_no_agents_can_still_connect_a_database(): void
    {
        $this->assertSame(0, $this->user->agents()->count());

        $this->actingAs($this->user)
            ->postJson(route('bi.connections.store'), $this->payload())
            ->assertStatus(201);

        $this->assertSame(1, BiConnection::count());
        $this->assertSame(0, DatabaseConnection::count());
    }

    /** Several, unlike an agent — the warehouse and the till on one board. */
    public function test_an_owner_may_keep_several_connections(): void
    {
        foreach (['Склад', 'Касса'] as $name) {
            $this->actingAs($this->user)
                ->postJson(route('bi.connections.store'), $this->payload(['name' => $name]))
                ->assertStatus(201);
        }

        $this->assertSame(2, BiConnection::ownedBy($this->user)->count());
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->user)->postJson(route('bi.connections.store'), $this->payload());

        $connection = BiConnection::sole();

        $this->assertSame('secret', $connection->password);
        $this->assertNotSame('secret', $connection->getRawOriginal('password'));
        $this->assertStringNotContainsString('secret', (string) $connection->getRawOriginal('password'));
    }

    /**
     * Test-then-save: a connection that has not just been proven is never
     * written, so an owner cannot end up with a stored connection that has
     * never worked and a board that silently shows nothing.
     */
    public function test_a_connection_that_will_not_dial_is_not_saved(): void
    {
        $this->driver->failWith = new DatabaseConnectionException(
            DatabaseConnectionException::AUTH_FAILED,
            'nope'
        );

        $this->actingAs($this->user)
            ->postJson(route('bi.connections.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('host');

        $this->assertSame(0, BiConnection::count());
    }

    public function test_test_endpoint_dials_without_saving(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('bi.connections.test'), $this->payload())
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, BiConnection::count(), 'Testing must not write a row.');
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('bi.connections.store'), $this->payload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_unknown_engine_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('bi.connections.store'), $this->payload(['engine' => 'oracle']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('engine');
    }

    public function test_an_owner_is_capped_at_the_connection_limit(): void
    {
        BiConnection::factory()->count(BiConnection::MAX_PER_OWNER)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson(route('bi.connections.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // --- Editing -------------------------------------------------------------

    /**
     * An empty password means "keep the stored one".
     *
     * The form never receives the secret back, so an owner editing a host
     * submits an empty password field — and must not thereby blank it.
     */
    public function test_an_empty_password_keeps_the_stored_one(): void
    {
        $connection = BiConnection::factory()->ready()->create([
            'user_id' => $this->user->id,
            'password' => 'original',
        ]);

        $this->actingAs($this->user)
            ->patchJson(route('bi.connections.update', $connection), $this->payload([
                'host' => 'moved.example.com',
                'password' => '',
            ]))
            ->assertOk();

        $fresh = $connection->fresh();

        $this->assertSame('moved.example.com', $fresh->host);
        $this->assertSame('original', $fresh->password);
    }

    public function test_a_new_password_replaces_the_stored_one(): void
    {
        $connection = BiConnection::factory()->ready()->create([
            'user_id' => $this->user->id,
            'password' => 'original',
        ]);

        $this->actingAs($this->user)
            ->patchJson(route('bi.connections.update', $connection), $this->payload(['password' => 'rotated']))
            ->assertOk();

        $this->assertSame('rotated', $connection->fresh()->password);
    }

    // --- Deleting ------------------------------------------------------------

    /**
     * Deleting a connection deletes the sources built on it — THROUGH ELOQUENT,
     * so the observer chain runs.
     *
     * The foreign key is `restrictOnDelete` rather than a cascade, deliberately:
     * a database-level cascade fires no model events, and in this module the
     * observers are the only thing that drops the runtime `bi_ds_*` tables and
     * the NDJSON shards. This test is what proves the controller does the work
     * the constraint refuses to do for it.
     */
    public function test_deleting_a_connection_removes_its_sources_through_eloquent(): void
    {
        $connection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $this->assertSame(1, BiSource::count());

        $this->actingAs($this->user)
            ->deleteJson(route('bi.connections.destroy', $connection))
            ->assertOk();

        $this->assertSame(0, BiConnection::count());
        $this->assertSame(0, BiSource::count());
        $this->assertSame(0, BiDataset::count());
    }

    public function test_a_connection_with_no_sources_deletes_cleanly(): void
    {
        $connection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->deleteJson(route('bi.connections.destroy', $connection))
            ->assertOk();

        $this->assertSame(0, BiConnection::count());
    }

    // --- Isolation -----------------------------------------------------------

    public function test_another_account_cannot_read_edit_or_delete_a_connection(): void
    {
        $connection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->getJson(route('bi.connections.index'))
            ->assertOk()
            ->assertJsonPath('connections', []);

        $this->actingAs($intruder)
            ->patchJson(route('bi.connections.update', $connection), $this->payload())
            ->assertForbidden();

        $this->actingAs($intruder)
            ->deleteJson(route('bi.connections.destroy', $connection))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->getJson(route('bi.connections.tables', $connection))
            ->assertForbidden();

        $this->assertDatabaseHas('bi_connections', ['id' => $connection->id]);
    }

    /**
     * test() now authorizes explicitly via BiConnectionPolicy::test() rather
     * than only through SaveBiConnectionRequest's 'update' check — a member
     * who only holds analytics.manage (not the Owner bypass $this->user
     * gets) must still be able to retest a saved connection exactly as
     * before.
     */
    public function test_a_member_with_analytics_manage_can_still_retest_a_saved_connection(): void
    {
        $connection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);
        $analyst = User::factory()->create();
        $role = Role::create(['team_id' => $this->user->current_team_id, 'slug' => 'analyst', 'name' => 'Analyst']);
        $role->syncPermissions([PermissionCatalog::ANALYTICS_MANAGE]);
        TeamMembership::create([
            'team_id' => $this->user->current_team_id, 'user_id' => $analyst->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
        $analyst->forceFill(['current_team_id' => $this->user->current_team_id])->save();

        $this->actingAs($analyst)
            ->postJson(route('bi.connections.retest', $connection), $this->payload(['password' => '']))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_guest_cannot_reach_any_connection_endpoint(): void
    {
        $connection = BiConnection::factory()->ready()->create(['user_id' => $this->user->id]);

        $this->getJson(route('bi.connections.index'))->assertUnauthorized();
        $this->postJson(route('bi.connections.store'), $this->payload())->assertUnauthorized();
        $this->postJson(route('bi.connections.test'), $this->payload())->assertUnauthorized();
        $this->patchJson(route('bi.connections.update', $connection), $this->payload())->assertUnauthorized();
        $this->deleteJson(route('bi.connections.destroy', $connection))->assertUnauthorized();
    }
}
