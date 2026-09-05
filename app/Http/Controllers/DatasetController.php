<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\AnalyzeDatasetRequest;
use App\Http\Requests\Dataset\ImportDatasetRequest;
use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Services\Dataset\ColumnMapper;
use App\Services\Dataset\DatasetFileReader;
use App\Services\Dataset\DatasetImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The Каталог tab: upload a spreadsheet, confirm what its columns mean, import.
 *
 * The upload is deliberately two requests, not one. `analyze` parses the file
 * and hands back its columns with sample values and a suggested mapping;
 * `import` writes nothing until the owner has confirmed that mapping. Guessing
 * the mapping and importing in one shot would be one click shorter and would
 * make the agent quote wholesale prices the first time a shop's export puts
 * "Цена закупки" before "Цена".
 *
 * Between the two, the file sits under the AGENT'S OWN pending directory. The
 * token names a file inside that directory and nothing else, so it cannot be
 * pointed at another tenant's upload however it is tampered with.
 */
class DatasetController extends Controller
{
    public function __construct(
        protected DatasetFileReader $reader,
        protected ColumnMapper $mapper,
        protected DatasetImporter $importer,
    ) {}

    /**
     * Current catalog state, used to refresh the tab in place after an import.
     */
    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        return response()->json(self::state($agent));
    }

    /**
     * Step 1: accept the file, read its header and a sample, and propose a
     * mapping. Nothing is imported and no existing catalog is touched.
     */
    public function analyze(AnalyzeDatasetRequest $request, Agent $agent): JsonResponse
    {
        $this->sweepExpiredUploads($agent);

        $token = Str::random(32);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'csv';
        $path = $this->pendingDirectory($agent)."/{$token}.{$extension}";

        Storage::disk('local')->put($path, $file->get());

        try {
            $parsed = $this->reader->read(
                Storage::disk('local')->path($path),
                (int) config('agent.dataset.max_rows'),
            );
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            Log::warning('Catalog analyze failed', [
                'agent_id' => $agent->id,
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('agents.catalog.errors.unreadable'),
            ], 422);
        }

        Storage::disk('local')->put(
            $this->pendingDirectory($agent)."/{$token}.json",
            json_encode([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
            ], JSON_UNESCAPED_UNICODE),
        );

        // Reuse the mapping from the last import when the file's columns are
        // unchanged: a shop re-uploading this month's export should not have to
        // re-answer the same seven questions.
        $previous = $agent->dataset;
        $reused = $previous && $previous->columns === $parsed['headers'] && ! empty($previous->mapping);

        return response()->json([
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
            'columns' => $parsed['headers'],
            'sample' => array_slice($parsed['rows'], 0, (int) config('agent.dataset.sample_rows')),
            'row_count' => count($parsed['rows']),
            'truncated' => $parsed['truncated'],
            // The sample rows go in with the headers: they are what lets a
            // column headed something the vocabulary has never seen still be
            // recognised as the product name, and what keeps an empty column out
            // of the extras.
            'mapping' => $reused ? $previous->mapping : $this->mapper->suggest($parsed['headers'], $parsed['rows']),
            'mapping_reused' => (bool) $reused,
        ]);
    }

    /**
     * Step 2: import the pending upload under the confirmed mapping, replacing
     * whatever catalog the agent had.
     */
    public function import(ImportDatasetRequest $request, Agent $agent): JsonResponse
    {
        $pending = $this->resolvePending($agent, $request->string('token')->toString());

        if ($pending === null) {
            return response()->json(['message' => __('agents.catalog.errors.upload_expired')], 422);
        }

        try {
            $parsed = $this->reader->read(
                Storage::disk('local')->path($pending['path']),
                (int) config('agent.dataset.max_rows'),
            );

            $result = $this->importer->import(
                $agent,
                $pending['filename'],
                $parsed['headers'],
                $parsed['rows'],
                $request->mapping(),
            );
        } catch (InvalidArgumentException $e) {
            // Mapping problems the owner can fix by choosing a different column
            // — the pending file stays put so they can retry without re-uploading.
            return response()->json([
                'message' => match ($e->getMessage()) {
                    'missing_name' => __('agents.catalog.errors.missing_name'),
                    'no_valid_rows' => __('agents.catalog.errors.no_valid_rows'),
                    default => __('agents.catalog.errors.import_failed'),
                },
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Catalog import failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('agents.catalog.errors.import_failed')], 422);
        }

        $this->forgetPending($agent, $request->string('token')->toString());

        return response()->json(self::state($agent->refresh()) + [
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'skipped_reasons' => $result['reasons'],
            'truncated' => $parsed['truncated'],
        ]);
    }

    /**
     * Discard an analyzed upload the owner decided not to import.
     */
    public function cancel(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        $token = (string) $request->input('token');

        if (preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
            $this->forgetPending($agent, $token);
        }

        return response()->json(['cancelled' => true]);
    }

    /**
     * Remove the catalog entirely. The agent falls straight back to answering
     * from the knowledge base — search_products stops being offered at all.
     */
    public function destroy(Request $request, Agent $agent): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $agent);

        DatasetProduct::query()->where('agent_id', $agent->id)->delete();
        Dataset::query()->where('agent_id', $agent->id)->delete();
        Storage::disk('local')->deleteDirectory($this->pendingDirectory($agent));

        if ($request->wantsJson()) {
            return response()->json(self::state($agent->refresh()));
        }

        return back()->with('status', __('agents.catalog.toasts.deleted'));
    }

    /**
     * One page of the owner's own catalog listing.
     *
     * This is the settings-screen browser and nothing else. It has no bearing on
     * what customers see: search_products keeps its own hard MAX_LIMIT, which is
     * about protecting the conversation, while this is about letting the owner
     * page through everything they uploaded.
     */
    public function products(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        return response()->json(self::page($agent, (int) $request->query('page', 1)));
    }

    /**
     * Everything the tab renders: the dataset's status, its mapping in terms of
     * the file's own column names, and the first page of its products.
     *
     * Static so AgentController can seed the tab on first paint with the same
     * shape this controller returns on every later refresh — one payload format,
     * so the Alpine component never has two versions of "the catalog" to handle.
     *
     * @return array<string, mixed>
     */
    public static function state(Agent $agent): array
    {
        $dataset = $agent->dataset()->first();

        if ($dataset === null) {
            return ['dataset' => null, 'preview' => [], 'pagination' => self::emptyPagination()];
        }

        return [
            'dataset' => [
                'filename' => $dataset->original_filename,
                'row_count' => $dataset->row_count,
                'skipped_count' => $dataset->skipped_count,
                'skipped_reasons' => $dataset->skipped_reasons ?? [],
                'imported_at' => $dataset->imported_at?->diffForHumans(),
                'columns' => $dataset->columns ?? [],
                'mapping' => $dataset->mapping ?? [],
            ],
            ...self::page($agent, 1),
        ];
    }

    /**
     * One page of products plus the counts the pager needs, in the same
     * `preview`/`pagination` shape state() returns — so the Alpine component
     * merges a page fetch and a fresh import with identical code.
     *
     * @return array{preview: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    protected static function page(Agent $agent, int $page): array
    {
        $perPage = (int) config('agent.dataset.preview_rows');

        $query = DatasetProduct::query()->where('agent_id', $agent->id);
        $total = (clone $query)->count();

        if ($total === 0) {
            return ['preview' => [], 'pagination' => self::emptyPagination()];
        }

        $lastPage = (int) ceil($total / $perPage);
        // Clamped rather than trusted: a stale "next" click after a re-upload
        // that shortened the catalog must land on the last real page, not on an
        // empty table that reads as data loss.
        $page = max(1, min($page, $lastPage));

        $products = $query
            ->orderBy('position')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (DatasetProduct $product) => [
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $product->stock,
                'category' => $product->category,
                'sku' => $product->sku,
                'url' => $product->url,
                // The shop's own attribute columns, under its own header names.
                // The preview renders whatever this file actually has rather
                // than a fixed five columns, so a laptop catalog shows Процессор
                // and ОЗУ instead of two empty columns headed Артикул and
                // Остаток — which read as data that failed to import.
                'extra' => $product->extra ?? [],
            ])
            ->all();

        return [
            'preview' => $products,
            'pagination' => [
                'page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                // Absolute row numbers for the "11–20 of 137" summary, so the
                // owner can tell at a glance where they are in a long catalog.
                'from' => ($page - 1) * $perPage + 1,
                'to' => ($page - 1) * $perPage + count($products),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    protected static function emptyPagination(): array
    {
        $perPage = (int) config('agent.dataset.preview_rows');

        return ['page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'from' => 0, 'to' => 0];
    }

    /**
     * Where this agent's not-yet-confirmed uploads live. Keyed by agent id, which
     * is what makes a stolen or guessed token useless: it can only ever name a
     * file inside the requesting agent's own directory.
     */
    protected function pendingDirectory(Agent $agent): string
    {
        return "catalog/{$agent->id}/pending";
    }

    /**
     * @return array{filename: string, path: string}|null
     */
    protected function resolvePending(Agent $agent, string $token): ?array
    {
        $metaPath = $this->pendingDirectory($agent)."/{$token}.json";

        if (! Storage::disk('local')->exists($metaPath)) {
            return null;
        }

        $meta = json_decode((string) Storage::disk('local')->get($metaPath), true);

        if (! is_array($meta) || ! isset($meta['path'], $meta['filename'])) {
            return null;
        }

        // The recorded path must still be inside this agent's own directory —
        // belt and braces against a metadata file written by an older version.
        if (! str_starts_with($meta['path'], $this->pendingDirectory($agent).'/')
            || ! Storage::disk('local')->exists($meta['path'])) {
            return null;
        }

        return ['filename' => (string) $meta['filename'], 'path' => (string) $meta['path']];
    }

    protected function forgetPending(Agent $agent, string $token): void
    {
        $directory = $this->pendingDirectory($agent);

        foreach (Storage::disk('local')->files($directory) as $file) {
            if (str_starts_with(basename($file), $token.'.')) {
                Storage::disk('local')->delete($file);
            }
        }
    }

    /**
     * Drop uploads the owner analyzed but never confirmed. Runs on the next
     * upload rather than on a schedule — the only thing that accumulates these
     * is the same person uploading again.
     */
    protected function sweepExpiredUploads(Agent $agent): void
    {
        $cutoff = now()->subMinutes((int) config('agent.dataset.pending_ttl_minutes'))->getTimestamp();
        $disk = Storage::disk('local');

        foreach ($disk->files($this->pendingDirectory($agent)) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }
}
