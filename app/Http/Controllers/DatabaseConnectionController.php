<?php

namespace App\Http\Controllers;

use App\Http\Requests\Database\SaveConnectionRequest;
use App\Http\Requests\Database\StoreMappingRequest;
use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Services\Database\ConnectionConfig;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseDriver;
use App\Services\Database\Discovery\DiscoveryProposal;
use App\Services\Database\Discovery\DiscoveryResult;
use App\Services\Database\Discovery\SchemaDiscoverer;
use App\Services\Database\DriverManager;
use App\Services\Database\LiveProductSource;
use App\Services\Dataset\ColumnMapper;
use App\Services\Dataset\ProductSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The «Подключение к базе данных» tab: connect an agent to the business's own
 * product database instead of an uploaded file.
 *
 * The flow mirrors the catalog's deliberately: nothing is saved until the
 * connection has actually been proven, and nothing is queried until the owner
 * has confirmed what each column means. Guessing either would have the agent
 * quoting a wholesale price column to customers, and here it would do so from
 * live data, forever, without a re-upload to notice.
 *
 * Secrets never travel back out. Every response goes through state(), which has
 * no branch that can include the password.
 */
class DatabaseConnectionController extends Controller
{
    public function __construct(
        protected DriverManager $drivers,
        protected ColumnMapper $mapper,
        protected SchemaDiscoverer $discoverer,
    ) {}

    /**
     * Current connection state, used to refresh the tab in place.
     */
    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('catalog.view', $agent);

        return response()->json(self::state($agent));
    }

    /**
     * Verify credentials WITHOUT saving anything.
     *
     * Rate limited at the route: this endpoint opens a TCP connection to a host
     * and port the caller chose, so unlimited use would make our server a port
     * scanner with a friendly UI.
     */
    public function test(SaveConnectionRequest $request, Agent $agent): JsonResponse
    {
        $config = $this->configFor($agent, $request->credentials());

        try {
            $this->drivers->make($config)->testConnection();
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e, record: false);
        }

        return response()->json([
            'ok' => true,
            'message' => __('agents.database.toasts.connection_ok'),
        ]);
    }

    /**
     * Test, then save. A connection that has not just been proven is never
     * written — the owner cannot end up with a stored connection that has never
     * worked and an agent that silently answers nothing.
     */
    public function store(SaveConnectionRequest $request, Agent $agent): JsonResponse
    {
        $credentials = $request->credentials();
        $config = $this->configFor($agent, $credentials);

        try {
            $this->drivers->make($config)->testConnection();
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e, record: false);
        }

        $connection = $agent->databaseConnection()->firstOrNew([]);

        // Pointing at a different server or database invalidates the table and
        // the mapping: the same column names on a different host are not the
        // same columns, and silently keeping them would query a table nobody
        // chose.
        $moved = $connection->exists && (
            $connection->engine !== $config->engine
            || $connection->host !== $config->host
            || (int) $connection->port !== $config->port
            || $connection->database !== $config->database
        );

        $connection->fill([
            'engine' => $config->engine,
            'host' => $config->host,
            'port' => $config->port,
            'database' => $config->database,
            'username' => $config->username,
            'password' => $config->password,
            'ssl' => $config->ssl,
        ]);

        $connection->status = DatabaseConnection::STATUS_READY;
        $connection->last_error = null;
        $connection->last_tested_at = now();

        if ($moved) {
            $connection->table_name = null;
            $connection->table_type = null;
            $connection->schema_columns = null;
            $connection->mapping = null;
            $connection->discovered_at = null;
        }

        $agent->databaseConnection()->save($connection);

        return response()->json(self::state($agent->refresh()) + ['ok' => true]);
    }

    /**
     * The tables and views the connection can see. Re-runnable on demand: a
     * customer's schema changes without telling us.
     */
    public function tables(Agent $agent): JsonResponse
    {
        $this->authorize('catalog.view', $agent);

        $connection = $this->connectionOrFail($agent);

        if ($connection === null) {
            return response()->json(['message' => __('agents.database.errors.not_connected')], 422);
        }

        try {
            $tables = $this->drivers->make(ConnectionConfig::fromModel($connection))->listTables();
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, ConnectionConfig::fromModel($connection), $e);
        }

        return response()->json([
            'tables' => array_slice($tables, 0, (int) config('agent.database.max_tables', 500)),
        ]);
    }

    /**
     * One table's columns and a few real rows, plus a suggested mapping.
     *
     * The suggestion reuses the catalog's own ColumnMapper, so a shop that has
     * mapped a spreadsheet before meets the same vocabulary and the same guesses
     * here — and the guess is still only ever a suggestion the owner confirms.
     */
    public function describe(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('catalog.view', $agent);

        $connection = $this->connectionOrFail($agent);

        if ($connection === null) {
            return response()->json(['message' => __('agents.database.errors.not_connected')], 422);
        }

        $table = (string) $request->input('table');
        $config = ConnectionConfig::fromModel($connection);

        try {
            $driver = $this->drivers->make($config);

            // The table must be one the SERVER just told us about. This is the
            // check that keeps a hand-crafted request from naming an arbitrary
            // identifier — the picker is a convenience, this is the rule.
            if (! $this->tableExists($driver->listTables(), $table)) {
                return response()->json(['message' => __('agents.database.errors.unknown_table')], 422);
            }

            $described = $driver->describeTable($table);
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e);
        }

        $names = array_column($described['columns'], 'name');

        // ColumnMapper works in header indexes (it was written for spreadsheets);
        // translate its answer back into column names.
        $suggested = [];
        foreach ($this->mapper->suggest($names) as $field => $index) {
            if ($field !== 'extra' && isset($names[$index]) && in_array($field, LiveProductSource::FIELDS, true)) {
                $suggested[$field] = $names[$index];
            }
        }

        return response()->json([
            'table' => $table,
            'columns' => $described['columns'],
            'sample' => $this->trimSample($described['sample'], $names),
            'mapping' => $connection->table_name === $table && ! empty($connection->mapping)
                ? $connection->mapping
                : $suggested,
        ]);
    }

    /**
     * Scan the connected database and propose where the products are.
     *
     * Saves nothing. The response is a proposal the owner reviews — with real
     * rows beside it — and either confirms or ignores in favour of the manual
     * picker below.
     *
     * Rate limited with the connection endpoints: it opens a connection, reads a
     * whole catalog, and may (when enabled, and only for a genuinely undecided
     * schema) make one model call.
     */
    public function discover(Agent $agent): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

        $connection = $this->connectionOrFail($agent);

        if ($connection === null) {
            return response()->json(['message' => __('agents.database.errors.not_connected')], 422);
        }

        $config = ConnectionConfig::fromModel($connection);

        try {
            $driver = $this->drivers->make($config);
            $result = $this->discoverer->discover($driver);
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e);
        }

        $proposals = [];

        foreach ($result->proposals as $proposal) {
            $proposals[] = $proposal->toArray() + [
                // Real rows, exactly as the agent would see them. This is the
                // part an owner can actually check: they know their own products
                // on sight, and column names prove nothing.
                'preview' => $this->preview($driver, $result, $proposal),
            ];
        }

        return response()->json([
            'ok' => true,
            'ambiguous' => $result->ambiguous,
            'method' => $result->method,
            'tables_scanned' => $result->schema->tableCount(),
            'declared_relations' => $result->schema->hasDeclaredForeignKeys(),
            'proposals' => $proposals,
        ]);
    }

    /**
     * Confirm one of the proposals.
     *
     * The browser sends a TABLE NAME and nothing else. The mapping and the joins
     * are recomputed here from a fresh scan rather than accepted from the
     * request — so what is stored is something this server derived from the
     * customer's own catalog moments ago, and a hand-made request cannot smuggle
     * in a mapping the owner never saw. Choosing something that is not one of
     * the proposals is refused; the manual endpoint below is where an owner
     * overrides the guess.
     */
    public function confirmDiscovery(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

        $connection = $this->connectionOrFail($agent);

        if ($connection === null) {
            return response()->json(['message' => __('agents.database.errors.not_connected')], 422);
        }

        $table = (string) $request->input('table');
        $config = ConnectionConfig::fromModel($connection);

        try {
            $result = $this->discoverer->discover($this->drivers->make($config));
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e);
        }

        $proposal = $result->proposalFor($table);

        if ($proposal === null) {
            return response()->json(['message' => __('agents.database.errors.unknown_table')], 422);
        }

        // Only the tables this plan actually reads are kept — see
        // DiscoveredSchema::only().
        $snapshot = $result->schema->only([$proposal->table, ...$proposal->joins->tables()]);

        $connection->table_name = $proposal->table;
        $connection->table_type = $proposal->type;
        $connection->schema_columns = $result->schema->columns($proposal->table);
        $connection->mapping = $proposal->roles->toMapping();
        $connection->joins = $proposal->joins->toArray();
        $connection->schema_snapshot = $snapshot->toArray();
        $connection->discovery = ['proposals' => array_map(
            fn ($candidate) => $candidate->toArray(),
            $result->proposals,
        )];
        $connection->discovery_confidence = $proposal->confidence;
        $connection->discovery_method = $result->method;
        $connection->discovered_at = now();
        $connection->status = DatabaseConnection::STATUS_READY;
        $connection->last_error = null;
        $connection->save();

        return response()->json(self::state($agent->refresh()) + ['ok' => true]);
    }

    /**
     * A proposal's sample rows, or none.
     *
     * A preview that fails is not a failed discovery: the owner can still read
     * the proposed mapping and confirm it, and one candidate's unreadable table
     * must not take the other candidates down with it.
     *
     * @return array<int, array<string, string>>
     */
    protected function preview(DatabaseDriver $driver, DiscoveryResult $result, DiscoveryProposal $proposal): array
    {
        try {
            return $this->discoverer->preview($driver, $result->schema, $proposal);
        } catch (DatabaseConnectionException|InvalidArgumentException $e) {
            Log::info('Discovery preview unavailable for one candidate', [
                'table' => $proposal->table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Persist the chosen table and mapping, after re-reading the schema and
     * confirming every named column is really there.
     *
     * The snapshot written here is what LiveProductSource validates against on
     * every search, so this is the only place a column name enters the system.
     */
    public function mapping(StoreMappingRequest $request, Agent $agent): JsonResponse
    {
        $connection = $this->connectionOrFail($agent);

        if ($connection === null) {
            return response()->json(['message' => __('agents.database.errors.not_connected')], 422);
        }

        $table = $request->string('table')->toString();
        $config = ConnectionConfig::fromModel($connection);

        try {
            $driver = $this->drivers->make($config);
            $tables = $driver->listTables();

            if (! $this->tableExists($tables, $table)) {
                return response()->json(['message' => __('agents.database.errors.unknown_table')], 422);
            }

            $described = $driver->describeTable($table);
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e);
        }

        $available = array_column($described['columns'], 'name');
        $mapping = $request->mapping();

        foreach ($mapping as $field => $column) {
            if (! in_array($column, $available, true)) {
                return response()->json([
                    'message' => __('agents.database.errors.unknown_column', ['column' => $column]),
                ], 422);
            }
        }

        $connection->table_name = $table;
        $connection->table_type = $this->tableType($tables, $table);
        $connection->schema_columns = $described['columns'];
        $connection->mapping = $mapping;
        $connection->discovered_at = now();
        $connection->status = DatabaseConnection::STATUS_READY;
        $connection->last_error = null;
        $connection->save();

        return response()->json(self::state($agent->refresh()) + ['ok' => true]);
    }

    /**
     * Switch which source answers search_products.
     *
     * Switching TO the live connection is refused unless it is actually usable —
     * an agent whose product tool has quietly stopped working is the one failure
     * mode this feature must not introduce.
     */
    public function source(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

        $source = (string) $request->input('source');

        if (! in_array($source, ProductSourceResolver::SOURCES, true)) {
            return response()->json(['message' => __('agents.database.errors.unknown_source')], 422);
        }

        if ($source === ProductSourceResolver::DATABASE && ! $agent->databaseConnection()->first()?->isUsable()) {
            return response()->json(['message' => __('agents.database.errors.not_ready')], 422);
        }

        $agent->product_source = $source;
        $agent->save();

        return response()->json(self::state($agent->refresh()) + ['ok' => true]);
    }

    /**
     * Forget the connection entirely, credentials included, and fall back to the
     * uploaded catalog.
     */
    public function destroy(Agent $agent): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

        $agent->databaseConnection()->delete();

        if ($agent->product_source === ProductSourceResolver::DATABASE) {
            $agent->product_source = ProductSourceResolver::DATASET;
            $agent->save();
        }

        return response()->json(self::state($agent->refresh()) + ['ok' => true]);
    }

    /**
     * Everything the tab renders. There is no branch here that emits the
     * password — the browser only ever learns WHETHER one is stored.
     *
     * Static so AgentController can seed the tab on first paint with the same
     * shape every later refresh returns.
     *
     * @return array<string, mixed>
     */
    public static function state(Agent $agent): array
    {
        $connection = $agent->databaseConnection()->first();
        $drivers = app(DriverManager::class);

        return [
            'engines' => $drivers->options(),
            'active_source' => $agent->product_source ?? ProductSourceResolver::DATASET,
            'has_dataset' => $agent->dataset()->exists(),
            'connection' => $connection === null ? null : [
                'engine' => $connection->engine,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'username' => $connection->username,
                'ssl' => (bool) $connection->ssl,
                // Not the secret — only that there is one, so the field can show
                // a masked placeholder and stay empty until it is retyped.
                'has_password' => filled($connection->getRawOriginal('password')),
                'status' => $connection->status,
                'last_error' => $connection->last_error,
                'last_tested_at' => $connection->last_tested_at?->diffForHumans(),
                'table' => $connection->table_name,
                'table_type' => $connection->table_type,
                'mapping' => $connection->mapping ?? [],
                'columns' => array_column($connection->schema_columns ?? [], 'name'),
                'discovered_at' => $connection->discovered_at?->diffForHumans(),
                'usable' => $connection->isUsable(),
                // What discovery composed, so the summary card can say where
                // each field comes from without re-scanning on every paint.
                'joins' => array_map(
                    fn (array $join) => ['table' => $join['table'], 'fields' => array_keys($join['columns'])],
                    $connection->joins ?? [],
                ),
                'confidence' => $connection->discovery_confidence,
                'method' => $connection->discovery_method,
            ],
        ];
    }

    /**
     * The credentials to dial with: what was submitted, except that an empty
     * password means "reuse the stored one".
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function configFor(Agent $agent, array $credentials): ConnectionConfig
    {
        $config = ConnectionConfig::fromArray($credentials);

        if ($config->password !== '') {
            return $config;
        }

        $stored = $agent->databaseConnection()->first();

        return $config->withPassword((string) ($stored?->password ?? ''));
    }

    /**
     * Record the failure on the connection (so the tab can show what went wrong
     * after a reload) and answer with a human sentence.
     *
     * The driver's own message goes to the log and nowhere else: it routinely
     * contains the DSN, the username and sometimes the server's banner.
     *
     * `$record` is off for the form paths (test, save). A typo in the host field
     * of the setup form must not mark the CURRENTLY WORKING saved connection as
     * broken and take the agent's product tool away — only a failure using the
     * stored credentials says anything about the stored connection.
     */
    protected function failure(Agent $agent, ConnectionConfig $config, DatabaseConnectionException $e, bool $record = true): JsonResponse
    {
        Log::warning('Live database connection failed', $config->forLog() + [
            'agent_id' => $agent->id,
            'reason' => $e->reason,
            'error' => $e->getMessage(),
        ]);

        if ($record) {
            $agent->databaseConnection()->first()?->forceFill([
                'status' => DatabaseConnection::STATUS_ERROR,
                'last_error' => $e->reason,
                'last_tested_at' => now(),
            ])->save();
        }

        return response()->json([
            'ok' => false,
            'reason' => $e->reason,
            'message' => __('agents.database.errors.'.$e->reason),
        ], 422);
    }

    protected function connectionOrFail(Agent $agent): ?DatabaseConnection
    {
        // Scoped through the agent relation, so a connection can only ever be
        // reached via the agent the policy just authorized.
        return $agent->databaseConnection()->first();
    }

    /**
     * @param  array<int, array{name: string, type: string}>  $tables
     */
    protected function tableExists(array $tables, string $table): bool
    {
        return in_array($table, array_column($tables, 'name'), true);
    }

    /**
     * @param  array<int, array{name: string, type: string}>  $tables
     */
    protected function tableType(array $tables, string $table): string
    {
        foreach ($tables as $candidate) {
            if ($candidate['name'] === $table) {
                return $candidate['type'];
            }
        }

        return 'table';
    }

    /**
     * Sample rows, keyed by column and shortened.
     *
     * A product table can hold a 50KB description or a base64 image; the mapping
     * screen needs enough to recognize a column, not the column's contents.
     *
     * @param  array<int, array<string, mixed>>  $sample
     * @param  string[]  $columns
     * @return array<int, array<string, string>>
     */
    protected function trimSample(array $sample, array $columns): array
    {
        $rows = [];

        foreach ($sample as $row) {
            $trimmed = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                $trimmed[$column] = match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? '1' : '0',
                    is_scalar($value) => mb_substr((string) $value, 0, 80),
                    default => '…',
                };
            }

            $rows[] = $trimmed;
        }

        return $rows;
    }
}
