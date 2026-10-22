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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DatasetController extends Controller
{
    private const PREVIEW_ROWS = 12;

    public function __construct(
        private readonly SchemaEditor $editor,
        private readonly IngestErrorPresenter $errors,
        private readonly ExternalDatasetImporter $external,
    ) {}

    public function show(BiDataset $dataset): JsonResponse
    {
        $this->authorize('view', $dataset);

        return response()->json($this->present($dataset));
    }

    public function updateColumns(UpdateBiColumnsRequest $request, BiDataset $dataset): JsonResponse
    {
        $this->authorize('update', $dataset);

        $edits = $request->validated('columns');

        $result = $this->editor->apply($dataset, $edits);

        if ($result['rebuild']) {
            if ($dataset->isExternal()) {

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

    public function rematerialize(Request $request, BiDataset $dataset): JsonResponse
    {
        $this->authorize('update', $dataset);

        if ($dataset->isExternal()) {

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

        $dataset->delete();

        return response()->json(['deleted' => true]);
    }

    protected function queueRebuild(BiDataset $dataset): void
    {
        $dataset->forceFill([
            'status' => BiDataset::STATUS_MATERIALIZING,
            'error_code' => null,
            'error_detail' => null,
        ])->save();

        MaterializeDatasetJob::dispatch($dataset);
    }

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

            'overridden' => $column->type !== $column->inferred_type,
            'role' => $column->role->value,
            'date_format' => $column->format['date_format'] ?? null,

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

    protected function preview(BiDataset $dataset, $columns): array
    {
        if ($dataset->isExternal() || ! $dataset->isReady() || $columns->isEmpty()) {
            return [];
        }

        $table = $dataset->physicalTable();

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

    protected function skippedReasons(BiDataset $dataset): array
    {
        $reasons = [];

        foreach ((array) ($dataset->skipped_reasons ?? []) as $reason => $count) {
            $key = 'bi.notes.skipped_'.$reason;
            $sentence = __($key, ['count' => $count]);

            if ($sentence !== $key) {
                $reasons[] = $sentence;
            }
        }

        return $reasons;
    }

    protected function dateFormatLabel(string $format): string
    {

        $example = new \DateTimeImmutable('2026-12-25 14:30:00');

        return $example->format($format);
    }
}
