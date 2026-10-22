<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bi\SaveBiConnectionRequest;
use App\Models\BiConnection;
use App\Models\BiSource;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Ingestion\ExternalDatasetImporter;
use App\Services\Database\ConnectionConfig;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DriverManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectionController extends Controller
{
    public function __construct(
        private readonly ExternalDatasetImporter $importer,
        private readonly DriverManager $drivers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BiConnection::class);

        $connections = BiConnection::ownedBy($request->user())
            ->orderBy('name')
            ->get();

        return response()->json([
            'connections' => $connections->map(fn (BiConnection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'engine' => $connection->engine,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'username' => $connection->username,
                'ssl' => (bool) $connection->ssl,
                'status' => $connection->status,
                'usable' => $connection->isUsable(),
                'label' => $connection->label(),
            ])->values()->all(),
            'engines' => $this->drivers->options(),
        ]);
    }

    public function test(SaveBiConnectionRequest $request, ?BiConnection $connection = null): JsonResponse
    {

        if ($connection !== null) {
            $this->authorize('test', $connection);
        }

        $config = $this->configFor($request, $connection);

        try {
            $this->drivers->make($config)->testConnection();
        } catch (DatabaseConnectionException $e) {
            return response()->json([
                'ok' => false,
                'reason' => $e->reason,
                'message' => __('agents.database.errors.'.$e->reason),
            ], 422);
        }

        return response()->json(['ok' => true, 'message' => __('bi.connect.tested')]);
    }

    public function store(SaveBiConnectionRequest $request): JsonResponse
    {
        $this->authorize('create', BiConnection::class);

        $user = $request->user();

        if (BiConnection::ownedBy($user)->count() >= BiConnection::MAX_PER_OWNER) {
            throw ValidationException::withMessages([
                'name' => __('bi.errors.too_many_connections', ['limit' => BiConnection::MAX_PER_OWNER]),
            ]);
        }

        $credentials = $request->credentials();
        $config = ConnectionConfig::fromArray($credentials);

        try {
            $this->drivers->make($config)->testConnection();
        } catch (DatabaseConnectionException $e) {
            throw ValidationException::withMessages([
                'host' => __('agents.database.errors.'.$e->reason),
            ]);
        }

        $connection = new BiConnection($credentials);
        $connection->user_id = $user->id;
        $connection->team_id = $user->activeTeamId();
        $connection->status = BiConnection::STATUS_READY;
        $connection->last_error = null;
        $connection->last_tested_at = now();
        $connection->save();

        return response()->json(['ok' => true, 'id' => $connection->id], 201);
    }

    public function update(SaveBiConnectionRequest $request, BiConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $credentials = $request->credentials();
        $config = $this->configFor($request, $connection);

        try {
            $this->drivers->make($config)->testConnection();
        } catch (DatabaseConnectionException $e) {
            throw ValidationException::withMessages([
                'host' => __('agents.database.errors.'.$e->reason),
            ]);
        }

        if ($credentials['password'] === '') {
            unset($credentials['password']);
        }

        $connection->fill($credentials);
        $connection->status = BiConnection::STATUS_READY;
        $connection->last_error = null;
        $connection->last_tested_at = now();
        $connection->save();

        return response()->json(['ok' => true]);
    }

    public function tables(Request $request, BiConnection $connection): JsonResponse
    {
        $this->authorize('test', $connection);

        try {
            $tables = $this->importer->listTables($connection);
        } catch (BiIngestionException $e) {

            return response()->json([
                'message' => $this->sentence($e),
                'errors' => ['connection' => [$this->sentence($e)]],
                'detail' => $e->detail(),
            ], 422);
        }

        return response()->json(['tables' => $tables]);
    }

    public function storeSource(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', BiSource::class);

        $validated = $request->validate([
            'bi_connection_id' => ['required', 'integer'],
            'tables' => ['required', 'array', 'min:1', 'max:25'],
            'tables.*' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $connection = BiConnection::ownedBy($request->user())
            ->whereKey($validated['bi_connection_id'])
            ->first();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'bi_connection_id' => __('bi.connect.not_found'),
            ]);
        }

        $this->authorize('view', $connection);

        if (! $connection->isUsable()) {
            throw ValidationException::withMessages([
                'bi_connection_id' => __('bi.connect.not_verified'),
            ]);
        }

        try {
            $source = $this->importer->import(
                $request->user(),
                $connection,
                $validated['tables'],
                $validated['name'] ?? null,
            );
        } catch (BiIngestionException $e) {
            throw ValidationException::withMessages(['tables' => $this->sentence($e)]);
        }

        if ($request->expectsJson()) {
            return response()->json(['source_id' => $source->id], 201);
        }

        return back()->with('status', __('bi.connect.registered'));
    }

    public function destroy(Request $request, BiConnection $connection): JsonResponse
    {
        $this->authorize('delete', $connection);

        DB::transaction(function () use ($connection) {

            $connection->sources()->get()->each->delete();

            $connection->delete();
        });

        return response()->json(['ok' => true]);
    }

    private function configFor(SaveBiConnectionRequest $request, ?BiConnection $connection): ConnectionConfig
    {
        $config = ConnectionConfig::fromArray($request->credentials());

        if ($config->password === '' && $connection !== null) {
            return $config->withPassword((string) ($connection->password ?? ''));
        }

        return $config;
    }

    protected function sentence(BiIngestionException $e): string
    {
        $key = 'bi.errors.'.$e->reason;

        $sentence = __($key, [
            'limit' => (int) config('agent.bi.ingest.max_datasets_per_owner'),
            'size' => (int) round(((int) config('agent.bi.ingest.max_file_size_kb')) / 1024),
            'format' => '',
        ]);

        return $sentence === $key ? __('bi.errors.internal_error') : $sentence;
    }
}
