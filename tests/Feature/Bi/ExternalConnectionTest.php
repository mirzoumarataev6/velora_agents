<?php

namespace Tests\Feature\Bi;

use App\Models\Agent;
use App\Models\BiColumn;
use App\Models\BiConnection;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DropsAnalyticalTables;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Pointing the BI module at a database the owner already connected.
 *
 * Exercised through the same FakeDatabaseDriver the agent-side connection tests use,
 * for the same reason its docblock gives: the suite cannot stand up a MySQL server, so
 * everything ABOVE the driver is tested against a fake that can be told to fail the way
 * a real server fails, while what the drivers actually emit is asserted separately in
 * tests/Unit/Database.
 *
 * The properties that matter here are all about NOT COPYING anything: a connection
 * dataset has no table of ours, is never materialized, and reads live on every query.
 */
class ExternalConnectionTest extends TestCase
{
    use DropsAnalyticalTables, RefreshDatabase;

    private User $user;

    private FakeDatabaseDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->driver = new FakeDatabaseDriver(
            tables: [
                ['name' => 'orders', 'type' => 'table'],
                ['name' => 'order_items', 'type' => 'table'],
                ['name' => 'v_daily_revenue', 'type' => 'view'],
            ],
            schema: [
                'orders' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'created_at', 'type' => 'datetime', 'numeric' => false],
                    ['name' => 'delivered_on', 'type' => 'date', 'numeric' => false],
                    ['name' => 'region', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'total', 'type' => 'decimal(12,2)', 'numeric' => true],
                    ['name' => 'is_paid', 'type' => 'tinyint(1)', 'numeric' => true],
                    ['name' => 'note', 'type' => 'text', 'numeric' => false],
                    ['name' => 'weird', 'type' => 'geometry', 'numeric' => false],
                ],
                'order_items' => [
                    ['name' => 'order_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'qty', 'type' => 'int', 'numeric' => true],
                ],
            ],
            sample: [
                ['id' => 1, 'region' => 'Душанбе', 'total' => '1500.50'],
                ['id' => 2, 'region' => 'Худжанд', 'total' => '2400.00'],
            ],
        );

        app(DriverManager::class)->register('mysql', 3306, 'MySQL / MariaDB', fn () => $this->driver);
    }

    /**
     * A verified BI connection — the module's OWN record, owned by `user_id`.
     *
     * Note what is not here: no agent. Building one used to require creating an
     * agent first, which is exactly the coupling this module no longer has.
     */
    private function connection(?User $owner = null): BiConnection
    {
        return BiConnection::factory()->ready()->create([
            'user_id' => ($owner ?? $this->user)->id,
        ]);
    }

    public function test_live_bi_query_runs_through_the_registered_connection_and_remains_tenant_bound(): void
    {
        $connection = $this->connection();
        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id, 'tables' => ['orders'],
        ])->assertCreated();
        $source = BiSource::sole();
        $dataset = $source->datasets()->firstOrFail();
        $spec = app(SpecValidator::class)->validate($dataset, [
            'dimensions' => [['column' => 'c3']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c4']], 'limit' => 10,
        ]);
        $this->driver->rows = [['d0' => 'Душанбе', 'm0' => 1500.5]];
        $result = app(QueryRunner::class)->run($spec);
        $this->assertSame(1500.5, $result->rows[0]['m0']);
        $this->assertCount(1, $this->driver->biQueries);
        $this->assertTrue(app(QueryRunner::class)->run($spec)->cached);
        $this->assertCount(1, $this->driver->biQueries);

        $other = User::factory()->create();
        $this->actingAs($other)->post(route('bi.sources.analyze', $source))->assertForbidden();

        $foreignConnection = $this->connection($other);
        $source->forceFill(['bi_connection_id' => $foreignConnection->id])->save();
        $spec->dataset->unsetRelations();
        $this->expectException(BiQueryException::class);
        app(QueryRunner::class)->run($spec, true);
    }

    public function test_only_verified_connections_are_offered(): void
    {
        $ready = $this->connection();
        $unverified = BiConnection::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson(route('bi.connections.index'));

        $response->assertOk();
        $ids = array_column($response->json('connections'), 'id');

        $this->assertContains($ready->id, $ids);
        $this->assertContains($unverified->id, $ids);

        // Listed, but flagged: an owner has to be able to SEE a connection that
        // has not verified in order to fix it. What `usable` gates is
        // registering tables from it, which the server refuses outright.
        $byId = collect($response->json('connections'))->keyBy('id');
        $this->assertTrue($byId[$ready->id]['usable']);
        $this->assertFalse($byId[$unverified->id]['usable']);

        // And registering from the unverified one is refused outright: a
        // connection that has never dialled would produce datasets that fail on
        // their first query, long after the owner stopped associating the
        // failure with the credentials they typed.
        $this->actingAs($this->user)
            ->postJson(route('bi.connections.sources'), [
                'bi_connection_id' => $unverified->id,
                'tables' => ['orders'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bi_connection_id');
    }

    /**
     * The picker never sends the secret.
     *
     * `username`, `host`, `port` and `database` ARE in the payload, deliberately:
     * the owner has to be able to tell two connections apart and to edit one, and
     * the edit form is populated from this response. A username is an identifier
     * the owner typed and can see on their own server.
     *
     * The password is different in kind, and it is absent — not masked, not
     * truncated, not sent as ciphertext. There is nothing in this response that
     * could be replayed against their database.
     */
    public function test_the_picker_never_exposes_the_password(): void
    {
        $connection = $this->connection();

        $response = $this->actingAs($this->user)->getJson(route('bi.connections.index'));
        $body = $response->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertSame('readonly', $response->json('connections.0.username'));
        // Nor the stored ciphertext.
        $this->assertStringNotContainsString(
            (string) $connection->getRawOriginal('password'),
            $body
        );
        $this->assertStringNotContainsString('secret', $body);

        $response->assertJsonPath('connections.0.database', 'shop');
        $response->assertJsonPath('connections.0.host', 'db.example.com');
    }

    public function test_it_lists_the_readable_tables_and_views(): void
    {
        $connection = $this->connection();

        $response = $this->actingAs($this->user)->getJson(route('bi.connections.tables', $connection));

        $response->assertOk();
        $this->assertSame(
            ['orders', 'order_items', 'v_daily_revenue'],
            array_column($response->json('tables'), 'name')
        );
        $response->assertJsonPath('tables.2.type', 'view');
    }

    /**
     * A connection that will not answer produces the driver's own classified reason,
     * not an empty table list the owner would read as "this database has no tables".
     */
    public function test_a_failing_connection_reports_why_rather_than_showing_nothing(): void
    {
        $connection = $this->connection();
        $this->driver->failWith = new DatabaseConnectionException(
            DatabaseConnectionException::AUTH_FAILED,
            'Access denied for user'
        );

        $response = $this->actingAs($this->user)->getJson(route('bi.connections.tables', $connection));

        $response->assertStatus(422);
        $message = $response->json('errors.connection.0');

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('bi.errors.', $message);
        $this->assertNotSame(__('bi.errors.internal_error'), $message, 'It should say the connection failed, not "internal error".');

        // The sentence promises details, so there must be some — and they must carry
        // the driver's own classified reason, which is what makes "wrong password"
        // distinguishable from "host unreachable".
        $detail = $response->json('detail');
        $this->assertNotEmpty($detail);
        $this->assertStringContainsString(DatabaseConnectionException::AUTH_FAILED, $detail);
        // And never a path off our own disk.
        $this->assertStringNotContainsString('/var/', (string) $detail);
    }

    public function test_registering_tables_creates_one_dataset_per_table_with_no_table_of_ours(): void
    {
        Queue::fake();
        $connection = $this->connection();

        $response = $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders', 'order_items'],
            'name' => 'Продажи 1С',
        ]);

        $response->assertStatus(201);

        $source = BiSource::sole();
        $this->assertSame(BiSource::KIND_CONNECTION, $source->kind);
        $this->assertSame('Продажи 1С', $source->name);
        $this->assertSame($connection->id, $source->bi_connection_id);
        // Ready at once: there is nothing to normalize and nothing to build.
        $this->assertSame(BiSource::STATUS_READY, $source->status);
        $this->assertNull($source->disk_path);

        $datasets = $source->datasets()->orderBy('id')->get();
        $this->assertCount(2, $datasets);
        $this->assertSame(['orders', 'order_items'], $datasets->pluck('external_table')->all());

        foreach ($datasets as $dataset) {
            $this->assertTrue($dataset->isExternal());
            $this->assertSame(BiDataset::STATUS_READY, $dataset->status);
            $this->assertFalse(
                Schema::hasTable($dataset->physicalTable()),
                'A live connection must not cause a table of ours to be created.'
            );
        }

        // No ingestion work at all — nothing was copied, so there is nothing to queue.
        Queue::assertNothingPushed();
    }

    /**
     * The engine's own type names, mapped onto the closed set the query layer
     * understands. Coarse on purpose — what matters is whether a column may be summed
     * and whether it may be bucketed by month.
     */
    public function test_engine_types_map_onto_our_closed_set(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $columns = BiDataset::sole()->columns()->get()->keyBy('source_name');

        $this->assertSame(ColumnType::Integer, $columns['id']->type);
        // `datetime` must not be matched by the narrower `date` check, or every
        // timestamp would silently lose its clock.
        $this->assertSame(ColumnType::DateTime, $columns['created_at']->type);
        $this->assertSame(ColumnType::Date, $columns['delivered_on']->type);
        $this->assertSame(ColumnType::Text, $columns['region']->type);
        $this->assertSame(ColumnType::Decimal, $columns['total']->type);
        $this->assertSame(ColumnType::Boolean, $columns['is_paid']->type);
        $this->assertSame(ColumnType::Text, $columns['note']->type);
        // An unrecognized type is text, which is always safe: it can be grouped and
        // counted, just not summed.
        $this->assertSame(ColumnType::Text, $columns['weird']->type);

        $this->assertSame(ColumnRole::Time, $columns['created_at']->role);
        $this->assertSame(ColumnRole::Measure, $columns['total']->role);
        $this->assertSame(ColumnRole::Dimension, $columns['region']->role);
    }

    /**
     * The customer's column name is DATA, exactly as a file's header is. The identifier
     * stays ours.
     */
    public function test_identifiers_stay_ours_and_the_customers_names_are_kept_as_data(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $columns = BiDataset::sole()->columns()->get();

        $this->assertSame(['c0', 'c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'], $columns->pluck('physical_name')->all());
        $this->assertSame('created_at', $columns[1]->source_name);
        // The engine's declared type is kept for the drawer to show.
        $this->assertSame('datetime', $columns[1]->format['external_type']);
    }

    public function test_sample_values_come_from_the_rows_the_driver_already_fetched(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $region = BiDataset::sole()->columns()->where('source_name', 'region')->sole();

        $this->assertSame(['Душанбе', 'Худжанд'], $region->sample_values);
        // Not measured, and deliberately so: a COUNT(DISTINCT) per column on a live
        // customer table is a scan we have no business running at registration time.
        $this->assertNull($region->distinct_count);
        $this->assertFalse($region->distinct_exact);
        $this->assertFalse($region->indexed, 'Indexing the customer\'s own table is their decision, not ours.');
    }

    public function test_the_drawer_marks_a_live_dataset_and_offers_no_row_preview(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->getJson(route('bi.datasets.show', BiDataset::sole()));

        $response->assertOk();
        $response->assertJsonPath('kind', BiSource::KIND_CONNECTION);
        $response->assertJsonPath('external_table', 'orders');
        // Previewing would mean querying the customer's server on every drawer open.
        $response->assertJsonPath('preview', []);
        // "Refresh schema" is the live equivalent of "rebuild".
        $response->assertJsonPath('can_rebuild', true);
    }

    /**
     * Refreshing re-reads the column list wholesale. A stale row describing a column
     * the table no longer has is a row a query could name.
     */
    public function test_refreshing_replaces_the_column_set(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $dataset = BiDataset::sole();
        $this->assertSame(8, $dataset->fresh()->column_count);

        // The customer dropped three columns and added one.
        $this->driver->schema['orders'] = [
            ['name' => 'id', 'type' => 'int', 'numeric' => true],
            ['name' => 'region', 'type' => 'varchar', 'numeric' => false],
            ['name' => 'currency', 'type' => 'varchar', 'numeric' => false],
        ];

        $this->actingAs($this->user)
            ->postJson(route('bi.datasets.rematerialize', $dataset))
            ->assertOk()
            ->assertJsonPath('refreshed', true);

        $fresh = $dataset->fresh();
        $this->assertSame(3, $fresh->column_count);
        $this->assertSame(
            ['id', 'region', 'currency'],
            $fresh->columns()->pluck('source_name')->all()
        );
        $this->assertSame(
            3,
            BiColumn::where('bi_dataset_id', $dataset->id)->count(),
            'The old columns must be gone, not merely supplemented.'
        );
        $this->assertSame(2, $fresh->version);
    }

    /**
     * Re-typing a live column takes effect immediately: there is no table of ours to
     * rebuild, only a change in how the next query reads it.
     */
    public function test_re_typing_a_live_column_does_not_queue_a_rebuild(): void
    {
        Queue::fake();
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $dataset = BiDataset::sole();
        $column = $dataset->columns()->where('source_name', 'is_paid')->sole();

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.columns', $dataset), [
                'columns' => [$column->id => ['type' => 'integer']],
            ])
            ->assertOk()
            ->assertJsonPath('rebuilding', false);

        $this->assertSame(ColumnType::Integer, $column->fresh()->type);
        $this->assertSame(2, $dataset->fresh()->version, 'The version still moves, so cached results are not reused.');
        Queue::assertNothingPushed();
    }

    public function test_registering_is_all_or_nothing_when_a_table_cannot_be_read(): void
    {
        $connection = $this->connection();

        // The schema read fails on the second table. Nothing may be left behind.
        $this->driver->failWith = new DatabaseConnectionException(
            DatabaseConnectionException::SCHEMA_CHANGED,
            'Table has no readable columns.'
        );

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders', 'order_items'],
        ])->assertStatus(422);

        $this->assertSame(0, BiSource::count(), 'A half-registered source is worse than none.');
        $this->assertSame(0, BiDataset::count());
    }

    public function test_the_dataset_quota_covers_registration(): void
    {
        config(['agent.bi.ingest.max_datasets_per_owner' => 2]);
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders', 'order_items', 'v_daily_revenue'],
        ])->assertStatus(422);

        $this->assertSame(0, BiDataset::count());
    }

    public function test_at_least_one_table_must_be_chosen(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)
            ->postJson(route('bi.connections.sources'), [
                'bi_connection_id' => $connection->id,
                'tables' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tables');
    }

    /* --------------------------------------------------------------- isolation */

    /**
     * A BI connection belongs to a USER directly. Reaching one across that
     * boundary would hand an account another account's database credentials.
     */
    public function test_another_accounts_connection_cannot_be_listed_or_read(): void
    {
        $intruder = User::factory()->create();
        $foreign = $this->connection();

        // Not offered.
        $this->actingAs($intruder)
            ->getJson(route('bi.connections.index'))
            ->assertOk()
            ->assertJsonPath('connections', []);

        // Not readable.
        $this->actingAs($intruder)
            ->getJson(route('bi.connections.tables', $foreign))
            ->assertForbidden();

        // Not registrable. A 422 rather than a 403: the id is scoped away by
        // `ownedBy` before the policy is consulted, so "this is not yours" and
        // "this does not exist" are the same answer — which is the answer that
        // confirms nothing.
        $this->actingAs($intruder)
            ->postJson(route('bi.connections.sources'), [
                'bi_connection_id' => $foreign->id,
                'tables' => ['orders'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bi_connection_id');

        $this->assertSame(0, BiSource::count());
    }

    public function test_a_nonexistent_connection_is_refused_by_validation(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('bi.connections.sources'), [
                'bi_connection_id' => 999999,
                'tables' => ['orders'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bi_connection_id');
    }

    public function test_a_guest_cannot_reach_the_connection_endpoints(): void
    {
        $connection = $this->connection();

        $this->getJson(route('bi.connections.index'))->assertUnauthorized();
        $this->getJson(route('bi.connections.tables', $connection))->assertUnauthorized();
        $this->postJson(route('bi.connections.sources'), [])->assertUnauthorized();
    }

    /**
     * Deleting a connection-backed source removes its datasets, and — because there was
     * never a table of ours — leaves the customer's database entirely untouched.
     */
    public function test_deleting_a_connection_source_removes_our_rows_only(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->user)->postJson(route('bi.connections.sources'), [
            'bi_connection_id' => $connection->id,
            'tables' => ['orders'],
        ])->assertStatus(201);

        $source = BiSource::sole();

        $this->actingAs($this->user)->delete(route('bi.sources.destroy', $source))->assertRedirect();

        $this->assertSame(0, BiSource::count());
        $this->assertSame(0, BiDataset::count());
        // The CONNECTION survives: deleting the tables you registered from a
        // database is not deleting the database. Registering others from the
        // same connection must still work afterwards.
        $this->assertDatabaseHas('bi_connections', ['id' => $connection->id]);
    }
}
