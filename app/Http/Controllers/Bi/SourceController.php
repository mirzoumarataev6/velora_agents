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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The data source list: upload a file, watch it process, delete it.
 *
 * The upload is ONE request and returns immediately. Nothing is parsed inline —
 * the file is stored, a row is created, and a job is queued on the `ingest` queue.
 * That is not an optimization: a 100MB spreadsheet takes minutes to read, and a
 * request that holds the browser for minutes is a request that dies behind a proxy
 * and leaves the owner with no idea whether their file arrived.
 *
 * The owner's own directory is `bi/{user_id}/sources/{id}`, composed from two
 * integers we own. There is no request-supplied segment anywhere in it, so there is
 * no traversal to defend against and no way to name another account's directory.
 */
class SourceController extends Controller
{
    public function __construct(private readonly IngestErrorPresenter $errors) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $this->authorize('viewAny', BiSource::class);

        $sources = BiSource::ownedBy($user)
            // Counts and statuses for the list, without N+1: a source card shows
            // how many datasets it produced and how far along they are.
            ->with(['datasets' => fn ($query) => $query->orderBy('id')])
            ->latest('id')
            ->get();

        return view('bi.data.index', [
            'sources' => $sources,
            'ingestErrors' => $this->errors,
            // Whether the page should start polling, decided from the DATA rather
            // than sniffed from the DOM. The first version of this looked for a
            // `.spinner` element, which quietly matched the connect modal's own
            // hidden button spinner — so every visit polled once, concluded that
            // everything had finished, and reloaded the page two seconds in.
            'hasPendingWork' => $this->hasPendingWork($sources),
            'maxMegabytes' => (int) round(((int) config('agent.bi.ingest.max_file_size_kb')) / 1024),
            'i18nNamespaces' => ['bi', 'agents', 'common'],
            'i18nReload' => true,
        ]);
    }

    /**
     * Accept an upload, store it, and queue the ingest.
     */
    public function store(StoreBiSourceRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $this->authorize('create', BiSource::class);

        // Checked BEFORE the file is written: an owner at their quota should be
        // told so, not have 100MB of their data staged on our disk first and then
        // be refused by the job three seconds later.
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

        // The row is saved first so the directory can be named after its id, which
        // is what keeps two simultaneous uploads of the same filename apart.
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

    /**
     * Poll for progress. Returns every source's status, so one request refreshes
     * the whole list rather than one per card.
     */
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

    /**
     * Re-run a failed ingest.
     *
     * Only from a failed state, and only for a file whose staged copy is still
     * there — retrying something that never got as far as storing its file would
     * fail identically, and offering the button would be a lie.
     */
    public function retry(Request $request, BiSource $source): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $source);

        if ($source->status !== BiSource::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'source' => __('bi.errors.internal_error'),
            ]);
        }

        // A source whose datasets got as far as being inferred is retried at the
        // materialization step — there is no reason to re-read the original file
        // when the normalized shards are already on disk.
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

        // Everything a source consists of goes with it, and none of it is done
        // here: BiSourceObserver walks the datasets (each queuing its own table
        // drop) and removes the staged upload. Deliberately in the observer rather
        // than in this method, so that deleting an ACCOUNT — which never comes
        // through this controller — cleans up just as completely.
        $source->delete();

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('status', __('bi.data.deleted'));
    }

    /**
     * Whether any source or dataset is still being worked on.
     *
     * @param  Collection<int, BiSource>  $sources
     */
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

    /**
     * One source as the list and the poller see it.
     *
     * The error is resolved to a TRANSLATED sentence here rather than in the view,
     * because the poller updates cards over JSON and both paths must show the same
     * words. `error_detail` rides alongside as the technical remainder.
     *
     * @return array<string, mixed>
     */
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
