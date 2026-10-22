<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bi\StoreBiSourceRequest;
use App\Jobs\Bi\MaterializeDatasetJob;
use App\Jobs\Bi\NormalizeSourceJob;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Services\Bi\IngestErrorPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function __construct(private readonly IngestErrorPresenter $errors) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $this->authorize('viewAny', BiSource::class);

        $sources = BiSource::ownedBy($user)

            ->with(['datasets' => fn ($query) => $query->orderBy('id')])
            ->latest('id')
            ->get();

        return view('bi.data.index', [
            'sources' => $sources,
            'ingestErrors' => $this->errors,

            'hasPendingWork' => $this->hasPendingWork($sources),
            'maxMegabytes' => (int) round(((int) config('agent.bi.ingest.max_file_size_kb')) / 1024),
            'i18nNamespaces' => ['bi', 'agents', 'common'],
            'i18nReload' => true,
        ]);
    }

    public function store(StoreBiSourceRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $this->authorize('create', BiSource::class);

        $this->assertWithinDatasetQuota($user);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');

        $source = new BiSource([
            'kind' => BiSource::KIND_FILE,
            'name' => (string) ($request->input('name') ?: $file->getClientOriginalName()),
            'original_filename' => $file->getClientOriginalName(),
            'bytes' => $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
            'extension' => $extension,
            'status' => BiSource::STATUS_PENDING,
        ]);

        $source->user_id = $user->id;
        $source->team_id = $user->activeTeamId();
        $source->save();

        $path = $source->storageDirectory().'/original.'.$extension;

        Storage::disk('local')->putFileAs(
            $source->storageDirectory(),
            $file,
            'original.'.$extension,
        );

        $source->forceFill(['disk_path' => $path])->save();

        NormalizeSourceJob::dispatch($source);

        if ($request->expectsJson()) {
            return response()->json(['source' => $this->present($source)], 201);
        }

        return back()->with('status', __('bi.data.queued'));
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BiSource::class);

        $sources = BiSource::ownedBy($request->user())
            ->with(['datasets' => fn ($query) => $query->orderBy('id')])
            ->latest('id')
            ->get();

        return response()->json([
            'sources' => $sources->map(fn (BiSource $source) => $this->present($source))->all(),
        ]);
    }

    public function retry(Request $request, BiSource $source): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $source);

        if ($source->status !== BiSource::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'source' => __('bi.errors.internal_error'),
            ]);
        }

        $resumable = $source->datasets()
            ->whereIn('status', [BiDataset::STATUS_FAILED, BiDataset::STATUS_MATERIALIZING])
            ->where('shard_count', '>', 0)
            ->get();

        if ($resumable->isNotEmpty()) {
            $source->forceFill([
                'status' => BiSource::STATUS_READY,
                'error_code' => null,
                'error_detail' => null,
            ])->save();

            foreach ($resumable as $dataset) {
                MaterializeDatasetJob::dispatch($dataset);
            }
        } else {
            $source->forceFill([
                'status' => BiSource::STATUS_PENDING,
                'error_code' => null,
                'error_detail' => null,
            ])->save();

            NormalizeSourceJob::dispatch($source);
        }

        if ($request->expectsJson()) {
            return response()->json(['source' => $this->present($source->fresh())]);
        }

        return back()->with('status', __('bi.data.queued'));
    }

    public function destroy(Request $request, BiSource $source): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $source);

        $source->delete();

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('status', __('bi.data.deleted'));
    }

    protected function hasPendingWork($sources): bool
    {
        $settled = [BiSource::STATUS_READY, BiSource::STATUS_FAILED];

        return $sources->contains(function (BiSource $source) use ($settled) {
            if (! in_array($source->status, $settled, true)) {
                return true;
            }

            return $source->datasets->contains(
                fn (BiDataset $dataset) => ! in_array($dataset->status, [BiDataset::STATUS_READY, BiDataset::STATUS_FAILED], true)
            );
        });
    }

    protected function present(BiSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'kind' => $source->kind,
            'status' => $source->status,
            'status_label' => __('bi.status.'.$source->status),
            'error' => $this->errors->sentence($source),
            'error_detail' => $source->error_detail,
            'bytes' => $source->bytes,
            'created_at' => $source->created_at?->toIso8601String(),
            'datasets' => $source->datasets->map(fn (BiDataset $dataset) => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'sheet_name' => $dataset->sheet_name,
                'status' => $dataset->status,
                'status_label' => __('bi.status.'.$dataset->status),
                'row_count' => $dataset->row_count,
                'column_count' => $dataset->column_count,
                'truncated' => (bool) $dataset->truncated,
                'skipped_rows' => $dataset->skipped_rows,
                'error' => $this->errors->sentence($dataset, $source->extension),
                'error_detail' => $dataset->error_detail,
            ])->all(),
        ];
    }

    protected function assertWithinDatasetQuota(mixed $user): void
    {
        $max = max(1, (int) config('agent.bi.ingest.max_datasets_per_owner', 25));

        if (BiDataset::ownedBy($user)->count() >= $max) {
            throw ValidationException::withMessages([
                'file' => __('bi.errors.too_many_datasets', ['limit' => $max]),
            ]);
        }
    }
}
