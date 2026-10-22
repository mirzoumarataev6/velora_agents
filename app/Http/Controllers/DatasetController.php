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

class DatasetController extends Controller
{
    public function __construct(
        protected DatasetFileReader $reader,
        protected ColumnMapper $mapper,
        protected DatasetImporter $importer,
    ) {}

    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('catalog.view', $agent);

        return response()->json(self::state($agent));
    }

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

        $previous = $agent->dataset;
        $reused = $previous && $previous->columns === $parsed['headers'] && ! empty($previous->mapping);

        return response()->json([
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
            'columns' => $parsed['headers'],
            'sample' => array_slice($parsed['rows'], 0, (int) config('agent.dataset.sample_rows')),
            'row_count' => count($parsed['rows']),
            'truncated' => $parsed['truncated'],

            'mapping' => $reused ? $previous->mapping : $this->mapper->suggest($parsed['headers'], $parsed['rows']),
            'mapping_reused' => (bool) $reused,
        ]);
    }

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

            return response()->json(['missing' => 0]);
        }

        return response()->json($summary);
    }

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

    public function cancel(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

        $token = (string) $request->input('token');

        if (preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
            $this->forgetPending($agent, $token);
        }

        return response()->json(['cancelled' => true]);
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse|JsonResponse
    {
        $this->authorize('catalog.manage', $agent);

        ProductImage::purgeFilesForAgent($agent->id);
        DatasetProduct::query()->where('agent_id', $agent->id)->delete();
        Dataset::query()->where('agent_id', $agent->id)->delete();
        Storage::disk('local')->deleteDirectory($this->pendingDirectory($agent));

        if ($request->wantsJson()) {
            return response()->json(self::state($agent->refresh()));
        }

        return back()->with('status', __('agents.catalog.toasts.deleted'));
    }

    public function products(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('catalog.view', $agent);

        return response()->json(self::page($agent, (int) $request->query('page', 1)));
    }

    public static function state(Agent $agent): array
    {
        $dataset = $agent->dataset()->first();

        if ($dataset === null) {
            return ['dataset' => null, 'preview' => [], 'pagination' => self::emptyPagination()];
        }

        $page = self::page($agent, 1);

        return [
            'dataset' => [
                'filename' => $dataset->original_filename,
                'row_count' => $page['pagination']['total'],
                'skipped_count' => $dataset->skipped_count,
                'skipped_reasons' => $dataset->skipped_reasons ?? [],
                'imported_at' => $dataset->imported_at?->diffForHumans(),

                'stale' => $dataset->isStale(),
                'columns' => $dataset->columns ?? [],
                'mapping' => $dataset->mapping ?? [],
            ],
            ...$page,
        ];
    }

    protected static function page(Agent $agent, int $page): array
    {
        $perPage = (int) config('agent.dataset.preview_rows');

        $query = DatasetProduct::query()->where('agent_id', $agent->id);
        $total = (clone $query)->count();

        if ($total === 0) {
            return ['preview' => [], 'pagination' => self::emptyPagination()];
        }

        $lastPage = (int) ceil($total / $perPage);

        $page = max(1, min($page, $lastPage));

        $products = $query
            ->orderBy('position')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->with('images')
            ->get()
            ->map(fn (DatasetProduct $product) => [
                'id' => $product->id,

                'images' => $product->images->map(fn (ProductImage $image) => [
                    'id' => $image->id, 'url' => $image->previewUrl(), 'position' => $image->position,
                ])->all(),
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $product->stock,
                'category' => $product->category,
                'sku' => $product->sku,
                'url' => $product->url,

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

                'from' => ($page - 1) * $perPage + 1,
                'to' => ($page - 1) * $perPage + count($products),
            ],
        ];
    }

    protected static function emptyPagination(): array
    {
        $perPage = (int) config('agent.dataset.preview_rows');

        return ['page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'from' => 0, 'to' => 0];
    }

    protected function pendingDirectory(Agent $agent): string
    {
        return "catalog/{$agent->id}/pending";
    }

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
