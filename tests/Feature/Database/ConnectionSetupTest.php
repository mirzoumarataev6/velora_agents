<?php

namespace Tests\Feature\Database;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Setting a live connection up: proving it before saving it, keeping the secret
 * out of every response, and refusing to be used as a port scanner.
 */
class ConnectionSetupTest extends TestCase
{
    use RefreshDatabase;

    protected FakeDatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDatabaseDriver;

        app(DriverManager::class)->register(
            'mysql',
            3306,
            'MySQL / MariaDB',
            fn () => $this->driver,
        );
    }

    protected function agent(): Agent
    {
        return Agent::factory()->for(User::factory())->create();
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentials(array $overrides = []): array
    {
        return $overrides + [
            'engine' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'readonly',
            'password' => 'hunter2',
            'ssl' => false,
        ];
    }

    public function test_a_working_connection_reports_success_without_saving_anything(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent->user)
            ->postJson(route('agents.database.test', $agent), $this->credentials());

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, $this->driver->connectionAttempts);
        // Testing is not saving: nothing was written.
        $this->assertDatabaseCount('database_connections', 0);
    }

    public function test_a_refused_password_reports_a_human_error_and_never_a_stack_trace(): void
    {
        $agent = $this->agent();

        $this->driver->failWith = new DatabaseConnectionException(
            DatabaseConnectionException::AUTH_FAILED,
            "SQLSTATE[HY000] [1045] Access denied for user 'readonly'@'10.0.0.4'",
        );

        $response = $this->actingAs($agent->user)
            ->postJson(route('agents.database.test', $agent), $this->credentials());

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'auth_failed'])
            ->assertJsonPath('message', __('agents.database.errors.auth_failed'));

        // Neither the driver's message nor the credentials come back out.
        $response->assertDontSee('SQLSTATE', false);
        $response->assertDontSee('hunter2', false);
        $response->assertDontSee('10.0.0.4', false);
    }

    public function test_each_failure_reason_maps_to_its_own_sentence(): void
    {
        $agent = $this->agent();

        foreach ([
            DatabaseConnectionException::UNREACHABLE,
            DatabaseConnectionException::UNKNOWN_DATABASE,
            DatabaseConnectionException::PERMISSION_DENIED,
            DatabaseConnectionException::TIMEOUT,
        ] as $reason) {
            $this->driver->failWith = new DatabaseConnectionException($reason, 'raw driver text');

            $this->actingAs($agent->user)
                ->postJson(route('agents.database.test', $agent), $this->credentials())
                ->assertStatus(422)
                ->assertJsonPath('reason', $reason)
                ->assertJsonPath('message', __('agents.database.errors.'.$reason));
        }
    }

    public function test_saving_stores_the_password_encrypted_and_never_echoes_it_back(): void
    {
        $agent = $this->agent();

        $response = $this->actingAs($agent->user)
            ->putJson(route('agents.database.store', $agent), $this->credentials());

        $response->assertOk();
        $response->assertDontSee('hunter2', false);
        $response->assertJsonPath('connection.has_password', true);

        $connection = DatabaseConnection::query()->firstOrFail();
        $this->assertSame('hunter2', $connection->password);
        $this->assertSame(DatabaseConnection::STATUS_READY, $connection->status);

        // At rest it is ciphertext, not the secret.
        $stored = DB::table('database_connections')->value('password');
        $this->assertNotSame('hunter2', $stored);
        $this->assertStringNotContainsString('hunter2', (string) $stored);
    }

    public function test_a_connection_that_fails_its_test_is_not_saved(): void
    {
        $agent = $this->agent();

        $this->driver->failWith = new DatabaseConnectionException(DatabaseConnectionException::UNREACHABLE);

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.store', $agent), $this->credentials())
            ->assertStatus(422);

        $this->assertDatabaseCount('database_connections', 0);
    }

    public function test_an_empty_password_field_keeps_the_stored_secret(): void
    {
        $agent = $this->agent();
        DatabaseConnection::factory()->for($agent)->create(['password' => 'original']);

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.store', $agent), $this->credentials(['password' => '', 'host' => 'db.example.com']))
            ->assertOk();

        $this->assertSame('original', $agent->databaseConnection()->first()->password);
    }

    public function test_moving_the_connection_to_another_server_clears_the_table_and_mapping(): void
    {
        $agent = $this->agent();
        DatabaseConnection::factory()->for($agent)->configured()->create();

        $this->actingAs($agent->user)
            ->putJson(route('agents.database.store', $agent), $this->credentials(['host' => 'other.example.com']))
            ->assertOk()
            ->assertJsonPath('connection.table', null);

        $connection = $agent->databaseConnection()->first();
        $this->assertNull($connection->table_name);
        $this->assertEmpty($connection->mapping);
    }

    public function test_the_state_payload_has_no_field_that_could_carry_the_secret(): void
    {
        $agent = $this->agent();
        DatabaseConnection::factory()->for($agent)->configured()->create(['password' => 'topsecret']);

        $response = $this->actingAs($agent->user)->getJson(route('agents.database.show', $agent));

        $response->assertOk();
        $response->assertDontSee('topsecret', false);
        $this->assertArrayNotHasKey('password', $response->json('connection'));
    }

    public function test_another_users_agent_is_untouchable(): void
    {
        $agent = $this->agent();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('agents.database.test', $agent), $this->credentials())
            ->assertForbidden();

        $this->actingAs($stranger)
            ->putJson(route('agents.database.store', $agent), $this->credentials())
            ->assertForbidden();

        $this->actingAs($stranger)
            ->getJson(route('agents.database.show', $agent))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->deleteJson(route('agents.database.destroy', $agent))
            ->assertForbidden();
    }

    public function test_an_internal_address_is_refused_before_a_socket_is_opened(): void
    {
        // Stated rather than inherited: a developer's .env may well have opened
        // this gate to reach a local database, and this test is about what
        // SHIPS. (phpunit.xml pins it too; this makes the intent visible here.)
        config(['agent.database.allow_private_hosts' => false]);

        // No fake driver here: the real MySqlDriver must refuse to dial. Without
        // this, "connect to any host:port" is an SSRF primitive pointed at our
        // own network from our own IP.
        app()->forgetInstance(DriverManager::class);
        $agent = $this->agent();

        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '192.168.1.1'] as $host) {
            $this->actingAs($agent->user)
                ->postJson(route('agents.database.test', $agent), $this->credentials(['host' => $host]))
                ->assertStatus(422)
                ->assertJsonPath('reason', DatabaseConnectionException::BLOCKED_HOST);
        }
    }

    /**
     * The documented escape hatch for self-hosted installs where the customer
     * database really is on the same private network. Asserted so that turning
     * it on is a deliberate, tested choice rather than a config key nobody has
     * ever exercised.
     */
    public function test_the_private_host_gate_can_be_opened_for_self_hosted_installs(): void
    {
        config(['agent.database.allow_private_hosts' => true]);

        app()->forgetInstance(DriverManager::class);
        $agent = $this->agent();

        $response = $this->actingAs($agent->user)
            ->postJson(route('agents.database.test', $agent), $this->credentials(['host' => '127.0.0.1', 'port' => 1]));

        // It gets past the guard and fails on the socket instead — which is the
        // whole difference between "we refuse to dial this" and "we dialled it".
        $response->assertStatus(422);
        $this->assertNotSame(
            DatabaseConnectionException::BLOCKED_HOST,
            $response->json('reason'),
        );
    }

    public function test_the_connection_test_is_rate_limited(): void
    {
        config(['agent.database.test_rate_limit' => 2]);
        $agent = $this->agent();

        $this->actingAs($agent->user)->postJson(route('agents.database.test', $agent), $this->credentials())->assertOk();
        $this->actingAs($agent->user)->postJson(route('agents.database.test', $agent), $this->credentials())->assertOk();

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.test', $agent), $this->credentials())
            ->assertStatus(429);
    }

    public function test_an_unsupported_engine_is_rejected_by_validation(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent->user)
            ->postJson(route('agents.database.test', $agent), $this->credentials(['engine' => 'oracle']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('engine');
    }

    public function test_disconnecting_removes_the_credentials_and_falls_back_to_the_catalog(): void
    {
        $agent = $this->agent();
        DatabaseConnection::factory()->for($agent)->configured()->create();
        $agent->forceFill(['product_source' => 'database'])->save();

        $this->actingAs($agent->user)
            ->deleteJson(route('agents.database.destroy', $agent))
            ->assertOk()
            ->assertJsonPath('connection', null)
            ->assertJsonPath('active_source', 'dataset');

        $this->assertDatabaseCount('database_connections', 0);
    }
}
