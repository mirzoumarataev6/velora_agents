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

/**
 * The BI module's OWN database connections.
 *
 * This used to list the connections an owner had set up for their agents and
 * borrow one. That was wrong in a way worth recording, because the reasoning
 * for it was superficially good: the owner had already typed those credentials
 * and had them verified, so asking again looked like asking them to trust us
 * twice.
 *
 * What it actually did was tie the business's analytics to the lifetime of an
 * assistant. `database_connections.agent_id` is unique and cascades from the
 * agent, so deleting an agent deleted its connection and left every BI source
 * built on it pointing at nothing — silently, because the foreign key merely
 * nulled. It also meant one credential served two features with different
 * threat models: an agent's connection is read by a language model answering
 * customers, and a BI connection is read by the owner analysing their own data.
 * Those should be revocable separately.
 *
 * So BI connections are their own records, owned by the user. An owner who
 * wants to analyse the same physical database an agent reads sets it up again
 * here. The duplicated password is the price, and it is the right one.
 *
 * Everything that makes a live connection SAFE is reused unchanged — the SSRF
 * guard, the encrypted cast, the read-only time-limited session, the
 * select-only re-check. Only the record moved.
 */
class ConnectionController extends Controller
{
    public function __construct(
        private readonly ExternalDatasetImporter $importer,
        private readonly DriverManager $drivers,
    ) {}

    /** This owner's BI connections, for the picker. Never a credential. */
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

    /**
     * Dial the host without saving anything.
     *
     * Takes the credentials from the form rather than from a stored row, so an
     * owner can prove a connection works before committing it — and so editing
     * one can be tested before it replaces a working record.
     */
    public function test(SaveBiConnectionRequest $request, ?BiConnection $connection = null): JsonResponse
    {
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

    /**
     * Test, then save. A connection that has not just been proven is never
     * written — an owner must not end up with a stored connection that has
     * never worked and a board that silently shows nothing.
     */
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

    /** Edit a saved connection. Same test-before-write rule as creating one. */
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

        // An empty password means "keep the stored one" — the form never
        // receives the secret back, so it cannot send it. Dropping the key is
        // what makes `fill()` leave the encrypted column alone.
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

    /**
     * The tables and views one connection can read.
     */
    public function tables(Request $request, BiConnection $connection): JsonResponse
    {
        $this->authorize('test', $connection);

        try {
            $tables = $this->importer->listTables($connection);
        } catch (BiIngestionException $e) {
            // A connection failure is the owner's to act on (wrong password,
            // server moved, firewall), so it is a 422 on the field they are
            // looking at rather than a 500 — and it carries the technical
            // remainder alongside the sentence, the same pairing the file
            // errors use. The sentence ends "подробности ниже", so there had
            // better be some.
            return response()->json([
                'message' => $this->sentence($e),
                'errors' => ['connection' => [$this->sentence($e)]],
                'detail' => $e->detail(),
            ], 422);
        }

        return response()->json(['tables' => $tables]);
    }

    /**
     * Register the chosen tables as a connection-backed source.
     */
    public function storeSource(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', BiSource::class);

        $validated = $request->validate([
            'bi_connection_id' => ['required', 'integer'],
            'tables' => ['required', 'array', 'min:1', 'max:25'],
            'tables.*' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        /*
         * Resolved through `ownedBy` and THEN through the policy.
         *
         * The scope is what makes another owner's id — or an agent's
         * `database_connections` id, which lives in a different table entirely
         * and can never match here — a validation error rather than a 403 that
         * confirms the row exists. The policy is the check that would still be
         * standing if someone replaced the scope with a plain `find`.
         */
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

    /**
     * Delete a connection, and the sources built on it.
     *
     * THE SOURCES GO FIRST, THROUGH ELOQUENT, and that is the mechanism rather
     * than a tidiness. The foreign key is `restrictOnDelete`, so the database
     * will refuse this delete while any source remains — deliberately, because
     * a cascade would not fire the observer chain (BiSourceObserver →
     * BiDatasetObserver → DropDatasetTableJob) that drops the runtime `bi_ds_*`
     * tables and the NDJSON shards. A cascade would delete the rows and leave
     * the physical tables orphaned, invisible to every migration.
     *
     * One transaction, so a failure part way through cannot leave the
     * connection gone and its sources stranded.
     */
    public function destroy(Request $request, BiConnection $connection): JsonResponse
    {
        $this->authorize('delete', $connection);

        DB::transaction(function () use ($connection) {
            // `each->delete()` rather than `->delete()` on the builder: a mass
            // delete issues one DELETE and fires no model events, which is the
            // whole thing being avoided here.
            $connection->sources()->get()->each->delete();

            $connection->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * The config to dial, with the stored password filled in when the form did
     * not supply one.
     */
    private function configFor(SaveBiConnectionRequest $request, ?BiConnection $connection): ConnectionConfig
    {
        $config = ConnectionConfig::fromArray($request->credentials());

        if ($config->password === '' && $connection !== null) {
            return $config->withPassword((string) ($connection->password ?? ''));
        }

        return $config;
    }

    /**
     * The owner-facing sentence for an ingestion failure raised on this path.
     *
     * The placeholders are filled from config rather than left in the string —
     * an unfilled `:limit` in the UI is the same bug as a missing translation.
     */
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
