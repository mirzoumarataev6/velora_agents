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

class DatabaseConnectionController extends Controller
{
    public function __construct(
        protected DriverManager $drivers,
        protected ColumnMapper $mapper,
        protected SchemaDiscoverer $discoverer,
    ) {}

    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('catalog.view', $agent);

        return response()->json(self::state($agent));
    }

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

            if (! $this->tableExists($driver->listTables(), $table)) {
                return response()->json(['message' => __('agents.database.errors.unknown_table')], 422);
            }

            $described = $driver->describeTable($table);
        } catch (DatabaseConnectionException $e) {
            return $this->failure($agent, $config, $e);
        }

        $names = array_column($described['columns'], 'name');

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

                'joins' => array_map(
                    fn (array $join) => ['table' => $join['table'], 'fields' => array_keys($join['columns'])],
                    $connection->joins ?? [],
                ),
                'confidence' => $connection->discovery_confidence,
                'method' => $connection->discovery_method,
            ],
        ];
    }

    protected function configFor(Agent $agent, array $credentials): ConnectionConfig
    {
        $config = ConnectionConfig::fromArray($credentials);

        if ($config->password !== '') {
            return $config;
        }

        $stored = $agent->databaseConnection()->first();

        return $config->withPassword((string) ($stored?->password ?? ''));
    }

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

        return $agent->databaseConnection()->first();
    }

    protected function tableExists(array $tables, string $table): bool
    {
        return in_array($table, array_column($tables, 'name'), true);
    }

    protected function tableType(array $tables, string $table): string
    {
        foreach ($tables as $candidate) {
            if ($candidate['name'] === $table) {
                return $candidate['type'];
            }
        }

        return 'table';
    }

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
