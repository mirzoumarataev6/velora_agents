<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\AnalyzeDatasetRequest;
use App\Http\Requests\Dataset\ImportDatasetRequest;
use App\Http\Requests\Dataset\PreviewDatasetUpdateRequest;
use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\ProductImage;
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
        $this->authorize('catalog.view', $agent);

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
     * Read-only: how many existing rows the current mapping would leave
     * unmatched (and so, under 'update', mark out of stock) if the owner
     * confirmed right now. Called from the mapping step whenever the
     * mapping changes, so the count the owner sees before they commit is
     * never stale. Nothing is written — DatasetImporter::previewUpdate()
     * runs the same matchRows() pass update() would, outside a transaction.
     */
    public function previewUpdate(PreviewDatasetUpdateRequest $request, Agent $agent): JsonResponse
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

            $summary = $this->importer->previewUpdate($agent, $parsed['headers'], $parsed['rows'], $request->mapping());
        } catch (InvalidArgumentException $e) {
            // Mapping isn't ready yet (e.g. no name column chosen) — no
            // count to show rather than an error, since this is advisory.
            return response()->json(['missing' => 0]);
        }

        return response()->json($summary);
    }

    /**
     * Step 2: import the pending upload under the confirmed mapping — either
     * 'update' (match existing rows by sku/name, keep photos, mark anything
     * missing as out of stock instead of deleting it) or 'replace' (today's
     * original behavior: the whole catalog, including every uploaded
     * photo, is retired and rebuilt from this file). See
     * DatasetImporter::update() / ::import() for the actual difference.
     */
    public function import(ImportDatasetRequest $request, Agent $agent): JsonResponse
    {
        $pending = $this->resolvePending($agent, $request->string('token')->toString());

        if ($pending === null) {
            return response()->json(['message' => __('agents.catalog.errors.upload_expired')], 422);
        }

        $mode = $request->mode();

        try {
            $parsed = $this->reader->read(
                Storage::disk('local')->path($pending['path']),
                (int) config('agent.dataset.max_rows'),
            );

            $result = $mode === 'update'
                ? $this->importer->update($agent, $pending['filename'], $parsed['headers'], $parsed['rows'], $request->mapping(), $request->markMissingOutOfStock())
                : $this->importer->import($agent, $pending['filename'], $parsed['headers'], $parsed['rows'], $request->mapping());
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
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('agents.catalog.errors.import_failed')], 422);
        }

        $this->forgetPending($agent, $request->string('token')->toString());

        return response()->json(self::state($agent->refresh()) + [
            'mode' => $mode,
            'skipped' => $result['skipped'],
            'skipped_reasons' => $result['reasons'],
            'truncated' => $parsed['truncated'],
        ] + ($mode === 'update'
            ? ['updated' => $result['updated'], 'added' => $result['added'], 'out_of_stock' => $result['out_of_stock'], 'ambiguous' => $result['ambiguous']]
            : ['imported' => $result['imported']]));
    }

    /**
     * Discard an analyzed upload the owner decided not to import.
     */
    public function cancel(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

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
        $this->authorize('catalog.manage', $agent);

        // Before the bulk product delete below: that single query cascades
        // product_images at the DB level but fires no model events, so
        // nothing else would ever remove the files themselves.
        ProductImage::purgeFilesForAgent($agent->id);
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
        $this->authorize('catalog.view', $agent);

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

        // page()'s own pagination.total is already a live COUNT(*) against
        // dataset_products — computed once here and reused below, rather
        // than also showing $dataset->row_count: that stored column is
        // whatever the LAST import's row count happened to be, and an
        // 'update' import can easily leave the catalog bigger (kept +
        // reappeared + newly out-of-stock rows) or smaller than the file
        // that touched it. Reading row_count instead of this is exactly
        // how "Товаров: 12" disagreed with "1-10 из 32" on the same tab.
        $page = self::page($agent, 1);

        return [
            'dataset' => [
                'filename' => $dataset->original_filename,
                'row_count' => $page['pagination']['total'],
                'skipped_count' => $dataset->skipped_count,
                'skipped_reasons' => $dataset->skipped_reasons ?? [],
                'imported_at' => $dataset->imported_at?->diffForHumans(),
                // Advisory only — see Dataset::isStale(); never read by the
                // agent, only shown on this tab.
                'stale' => $dataset->isStale(),
                'columns' => $dataset->columns ?? [],
                'mapping' => $dataset->mapping ?? [],
            ],
            ...$page,
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
            ->with('images')
            ->get()
            ->map(fn (DatasetProduct $product) => [
                'id' => $product->id,
                // The owner's own uploads for this row — what the Каталог tab's
                // photo cell renders and targets uploads/removals at. Unrelated
                // to the file's own image_url column below, which the preview
                // still shows as plain imported data either way. previewUrl(),
                // not url() — relative, so it loads from whatever origin this
                // dashboard is actually being viewed from (see
                // ProductImage::previewUrl()); channel drivers use url() instead.
                'images' => $product->images->map(fn (ProductImage $image) => [
                    'id' => $image->id, 'url' => $image->previewUrl(), 'position' => $image->position,
                ])->all(),
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
