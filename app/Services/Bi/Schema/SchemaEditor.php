<?php

namespace App\Services\Bi\Schema;

use App\Models\BiColumn;
use App\Models\BiDataset;
use Illuminate\Support\Facades\DB;

class SchemaEditor
{
    public const EDITABLE = ['type', 'role', 'label', 'description', 'date_format'];

    public function apply(BiDataset $dataset, array $edits): array
    {

        $columns = $dataset->columns()->get()->keyBy('id')->all();

        $changed = 0;
        $rebuildColumns = [];

        DB::transaction(function () use ($columns, $edits, &$changed, &$rebuildColumns) {
            foreach ($edits as $id => $attributes) {
                $column = $columns[(int) $id] ?? null;

                if ($column === null) {
                    continue;
                }

                $dirty = $this->applyToColumn($column, $attributes);

                if ($dirty === []) {
                    continue;
                }

                $changed++;

                if (array_intersect($dirty, ['type', 'format'])) {
                    $rebuildColumns[] = $column->displayName();
                }
            }
        });

        return [
            'changed' => $changed,
            'rebuild' => $rebuildColumns !== [],
            'rebuild_columns' => $rebuildColumns,
        ];
    }

    public function wouldRebuild(BiDataset $dataset, array $edits): bool
    {
        foreach ($dataset->columns()->get() as $column) {
            $attributes = $edits[$column->id] ?? null;

            if (! is_array($attributes)) {
                continue;
            }

            $type = $this->resolveType($attributes['type'] ?? null);

            if ($type !== null && $type !== $column->type) {
                return true;
            }

            if ($this->resolveDateFormat($attributes, $column) !== ($column->format['date_format'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    protected function applyToColumn(BiColumn $column, array $attributes): array
    {
        $dirty = [];

        $type = $this->resolveType($attributes['type'] ?? null);

        if ($type !== null && $type !== $column->type) {
            $column->type = $type;
            $dirty[] = 'type';
        }

        $role = $this->resolveRole($attributes['role'] ?? null);

        if ($role !== null && $role !== $column->role) {
            $column->role = $role;
            $dirty[] = 'role';
        }

        if (array_key_exists('label', $attributes)) {
            $label = $this->trimToNull($attributes['label'], 255);

            if ($label !== $column->label) {
                $column->label = $label;
                $dirty[] = 'label';
            }
        }

        if (array_key_exists('description', $attributes)) {
            $description = $this->trimToNull($attributes['description'], 512);

            if ($description !== $column->description) {
                $column->description = $description;
                $dirty[] = 'description';
            }
        }

        $format = $column->format ?? [];
        $newFormat = $this->resolveDateFormat($attributes, $column);

        if ($newFormat !== ($format['date_format'] ?? null)) {
            if ($newFormat === null) {
                unset($format['date_format']);
            } else {
                $format['date_format'] = $newFormat;

                $format['date_ambiguous'] = false;
                $format['date_order'] = str_starts_with($newFormat, 'd') ? 'dmy' : 'mdy';
            }

            $column->format = $format === [] ? null : $format;
            $dirty[] = 'format';
        }

        if (array_intersect($dirty, ['type', 'role']) !== []) {
            $column->indexed = $this->shouldIndex($column);
        }

        if ($dirty !== []) {
            $column->save();
        }

        return $dirty;
    }

    protected function shouldIndex(BiColumn $column): bool
    {
        if ($column->type->isTemporal()) {
            return true;
        }

        if ($column->role !== ColumnRole::Dimension || $column->isLongText()) {
            return false;
        }

        $limit = max(1, (int) config('agent.bi.ingest.dimension_distinct_limit', 1000));

        return (bool) $column->distinct_exact
            && (int) $column->distinct_count > 1
            && (int) $column->distinct_count <= $limit;
    }

    protected function resolveType(mixed $value): ?ColumnType
    {
        return is_string($value) ? ColumnType::tryFrom($value) : null;
    }

    protected function resolveRole(mixed $value): ?ColumnRole
    {
        return is_string($value) ? ColumnRole::tryFrom($value) : null;
    }

    protected function resolveDateFormat(array $attributes, BiColumn $column): ?string
    {
        if (! array_key_exists('date_format', $attributes)) {
            return $column->format['date_format'] ?? null;
        }

        $requested = $attributes['date_format'];

        $type = $this->resolveType($attributes['type'] ?? null) ?? $column->type;

        if (! $type->isTemporal() || ! is_string($requested) || $requested === '') {
            return null;
        }

        return in_array($requested, self::selectableDateFormats(), true)
            ? $requested
            : ($column->format['date_format'] ?? null);
    }

    public static function selectableDateFormats(): array
    {
        return [
            'd.m.Y', 'm.d.Y',
            'd/m/Y', 'm/d/Y',
            'd-m-Y', 'm-d-Y',
            'd.m.y', 'm.d.y',
            'Y-m-d',
            'd.m.Y H:i', 'd.m.Y H:i:s',
            'Y-m-d H:i', 'Y-m-d H:i:s',
        ];
    }

    protected function trimToNull(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $limit);
    }
}
