<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bi\UpdateBiColumnsRequest;
use App\Jobs\Bi\MaterializeDatasetJob;
use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\IngestErrorPresenter;
use App\Services\Bi\Ingestion\ExternalDatasetImporter;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use App\Services\Bi\Schema\SchemaEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The schema drawer: what a dataset's columns are, and the owner's power to say
 * otherwise.
 *
 * This is the surface that makes the honest parts of ingestion honest. Inference
 * reports what it guessed and what it rejected; without somewhere to correct it, that
 * reporting would just be an apology. So every judgement the module made is visible
 * here and every one of them is editable:
 *
 *   - the TYPE, including the date pattern for the one case that cannot be settled
 *     from the data (`03.04.2026` — April or March);
 *   - the ROLE, for the two cases inference reliably gets wrong (a year stored as an
 *     integer, an id column);
 *   - the LABEL a chart shows, and a DESCRIPTION that later feeds the AI analyst's
 *     schema context — the single most effective fix when it keeps choosing
 *     `sum_base` over `sum_with_vat`.
 *
 * Reads are scoped by policy on the dataset, and the preview reads the dataset's own
 * physical table — whose name comes from its primary key, never from the request.
 */
class DatasetController extends Controller
{
    /** Preview rows shown in the drawer. Enough to recognize the data, not to browse it. */
    private const PREVIEW_ROWS = 12;

    public function __construct(
        private readonly SchemaEditor $editor,
        private readonly IngestErrorPresenter $errors,
        private readonly ExternalDatasetImporter $external,
    ) {}

    /**
     * One dataset's schema and a sample of its rows.
     */
    public function show(BiDataset $dataset): JsonResponse
    {
        $this->authorize('view', $dataset);

        return response()->json($this->present($dataset));
    }

    /**
     * Apply the owner's corrections.
     *
     * A type or date-pattern change rebuilds the table from the shards; a label,
     * role or description change does not. Which of those happened is reported back,
     * so the UI can say "перестраиваем таблицу" only when it is true.
     */
    public function updateColumns(UpdateBiColumnsRequest $request, BiDataset $dataset): JsonResponse
    {
        $this->authorize('update', $dataset);

        /** @var array<int|string, array<string, mixed>> $edits */
        $edits = $request->validated('columns');

        $result = $this->editor->apply($dataset, $edits);

        if ($result['rebuild']) {
            if ($dataset->isExternal()) {
                // Nothing to rebuild: a connection-backed dataset has no table of
                // ours. The new type simply governs how the next live query reads
                // the column, which takes effect immediately.
                $dataset->forceFill(['version' => (int) $dataset->version + 1])->save();
            } else {
                $this->queueRebuild($dataset);
            }
        }

        return response()->json([
            'changed' => $result['changed'],
            'rebuilding' => $result['rebuild'] && ! $dataset->isExternal(),
            'rebuild_columns' => $result['rebuild_columns'],
            'dataset' => $this->present($dataset->fresh()),
        ]);
    }

    /**
     * Rebuild the table from the normalized shards, without re-reading the original
     * file.
     *
     * Offered as its own action as well as being triggered by a type change, because
     * it is also the recovery path for a materialization that failed half way — and
     * in that case the owner has nothing to edit, only something to retry.
     */
    public function rematerialize(Request $request, BiDataset $dataset): JsonResponse
    {
        $this->authorize('update', $dataset);

        if ($dataset->isExternal()) {
            // The equivalent action for a live table is re-reading its schema: there
            // are no rows of ours to rebuild, but the column list may have moved.
            try {
                $this->external->refresh($dataset);
            } catch (BiIngestionException $e) {
                throw ValidationException::withMessages([
                    'dataset' => __('bi.errors.'.$e->reason, ['limit' => 0, 'size' => 0, 'format' => '']),
                ]);
            }

            return response()->json(['refreshed' => true, 'dataset' => $this->present($dataset->fresh())]);
        }

        if ((int) $dataset->shard_count < 1) {
            // No normalized copy to rebuild from. Re-importing the file is the honest
            // instruction, and offering a button that cannot work would not be.
            throw ValidationException::withMessages([
                'dataset' => __('bi.schema.no_shards'),
            ]);
        }

        $this->queueRebuild($dataset);

        return response()->json(['rebuilding' => true, 'dataset' => $this->present($dataset->fresh())]);
    }

    public function update(Request $request, BiDataset $dataset): JsonResponse
    {
        $this->authorize('update', $dataset);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $dataset->forceFill(['name' => trim($validated['name'])])->save();

        return response()->json(['dataset' => $this->present($dataset->fresh())]);
    }

    public function destroy(BiDataset $dataset): JsonResponse
    {
        $this->authorize('delete', $dataset);

        // BiDatasetObserver queues the table drop and removes the shards.
        $dataset->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Put the dataset back into a rebuilding state and queue the work.
     *
     * The status is set here rather than in the job so the drawer reflects it on the
     * very next poll — a queued job may not start for seconds, and a row that still
     * says "готово" while its table is about to be dropped is the wrong thing to show.
     */
    protected function queueRebuild(BiDataset $dataset): void
    {
        $dataset->forceFill([
            'status' => BiDataset::STATUS_MATERIALIZING,
            'error_code' => null,
            'error_detail' => null,
        ])->save();

        MaterializeDatasetJob::dispatch($dataset);
    }

    /**
     * One dataset as the drawer sees it: its columns, what inference measured, and a
     * sample of real rows.
     *
     * @return array<string, mixed>
     */
    protected function present(BiDataset $dataset): array
    {
        $columns = $dataset->columns()->get();

        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'sheet_name' => $dataset->sheet_name,
            'status' => $dataset->status,
            'status_label' => __('bi.status.'.$dataset->status),
            'kind' => $dataset->isExternal() ? BiSource::KIND_CONNECTION : BiSource::KIND_FILE,
            'external_table' => $dataset->external_table,
            'row_count' => $dataset->row_count,
            'column_count' => $dataset->column_count,
            'version' => $dataset->version,
            'truncated' => (bool) $dataset->truncated,
            'skipped_rows' => $dataset->skipped_rows,
            'skipped_reasons' => $this->skippedReasons($dataset),
            'can_rebuild' => $dataset->isExternal() || (int) $dataset->shard_count > 0,
            'error' => $this->errors->sentence($dataset, $dataset->source?->extension),
            'error_detail' => $this->errors->detail($dataset),
            'materialized_at' => $dataset->materialized_at?->toIso8601String(),
            'columns' => $columns->map(fn (BiColumn $column) => $this->presentColumn($column))->all(),
            'preview' => $this->preview($dataset, $columns),
            'options' => [
                'types' => array_map(
                    fn (string $type) => ['value' => $type, 'label' => __('bi.types.'.$type)],
                    ColumnType::values()
                ),
                'roles' => array_map(
                    fn (string $role) => ['value' => $role, 'label' => __('bi.roles.'.$role)],
                    ColumnRole::values()
                ),
                'date_formats' => array_map(
                    fn (string $format) => ['value' => $format, 'label' => $this->dateFormatLabel($format)],
                    SchemaEditor::selectableDateFormats()
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentColumn(BiColumn $column): array
    {
        return [
            'id' => $column->id,
            'ordinal' => $column->ordinal,
            'source_name' => $column->source_name,
            'physical_name' => $column->physical_name,
            'label' => $column->label,
            'display_name' => $column->displayName(),
            'type' => $column->type->value,
            'inferred_type' => $column->inferred_type->value,
            // Shown as "you changed this", with the original offered back.
            'overridden' => $column->type !== $column->inferred_type,
            'role' => $column->role->value,
            'date_format' => $column->format['date_format'] ?? null,
            // The disclosure that makes the date guess honest.
            'date_ambiguous' => (bool) ($column->format['date_ambiguous'] ?? false),
            'external_type' => $column->format['external_type'] ?? null,
            'temporal' => $column->type->isTemporal(),
            'distinct_count' => $column->distinct_count,
            'distinct_exact' => (bool) $column->distinct_exact,
            'blank_count' => $column->blank_count,
            'cast_failures' => $column->cast_failures,
            'cast_failure_samples' => $column->cast_failure_samples ?? [],
            'sample_values' => $column->sample_values ?? [],
            'min_value' => $column->min_value,
            'max_value' => $column->max_value,
            'indexed' => (bool) $column->indexed,
            'description' => $column->description,
        ];
    }

    /**
     * A few real rows, keyed by physical column name.
     *
     * File-backed only. A live connection's preview would mean querying the
     * customer's server on every drawer open, and the sample values captured at
     * registration already show the owner what the columns hold.
     *
     * @param  Collection<int, BiColumn>  $columns
     * @return array<int, array<string, mixed>>
     */
    protected function preview(BiDataset $dataset, $columns): array
    {
        if ($dataset->isExternal() || ! $dataset->isReady() || $columns->isEmpty()) {
            return [];
        }

        $table = $dataset->physicalTable();

        // The table is dropped and rebuilt on every materialization, so a drawer
        // opened during a rebuild would query something that is not there. An empty
        // preview is the correct answer in that window, not an error.
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->orderBy(BiDataset::ROW_KEY)
            ->limit(self::PREVIEW_ROWS)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * The skipped-row breakdown, already translated.
     *
     * @return array<int, string>
     */
    protected function skippedReasons(BiDataset $dataset): array
    {
        $reasons = [];

        foreach ((array) ($dataset->skipped_reasons ?? []) as $reason => $count) {
            $key = 'bi.notes.skipped_'.$reason;
            $sentence = __($key, ['count' => $count]);

            // An unknown reason key would otherwise render as `bi.notes.skipped_x`.
            if ($sentence !== $key) {
                $reasons[] = $sentence;
            }
        }

        return $reasons;
    }

    /**
     * A date pattern as a person reads it: `d.m.Y` → `31.12.2026`.
     *
     * Showing the pattern itself would be asking the owner to know PHP's format
     * characters. An example date they can compare against their own file is the
     * thing that actually resolves "is this день.месяц or month.day".
     */
    protected function dateFormatLabel(string $format): string
    {
        // A deliberately unambiguous date: day 25 and month 12 read differently under
        // every candidate pattern, which is exactly what makes the example useful.
        $example = new \DateTimeImmutable('2026-12-25 14:30:00');

        return $example->format($format);
    }
}
