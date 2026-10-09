<?php

namespace App\Services\Bi\Schema;

use App\Models\BiColumn;
use App\Models\BiDataset;
use Illuminate\Support\Facades\DB;

/**
 * Applies the owner's corrections to a dataset's schema, and decides what they cost.
 *
 * THE DISTINCTION THIS CLASS EXISTS FOR: some edits are free and some rebuild a
 * table. Renaming a column to "Выручка, сомони" is a label — it takes effect on the
 * next page load. Re-typing it from text to a number changes the physical column's
 * DDL, so the table has to be dropped and reloaded from the shards.
 *
 * Collapsing the two would be the wrong trade in either direction: rebuilding on
 * every label edit would make a typo cost a minute of worker time, and *not*
 * rebuilding on a type edit would leave a column whose stored type no longer matches
 * the data in it — the query compiler would then emit `SUM()` over a VARCHAR and the
 * dashboard would be wrong rather than broken, which is worse.
 *
 * So `apply()` reports back whether a rebuild is required, the caller queues it, and
 * the UI can tell the owner in advance which of their edits is the expensive one.
 */
class SchemaEditor
{
    /**
     * Attributes an owner may change on a column.
     *
     * A closed list, not a mass assignment: `physical_name` and `ordinal` are ours
     * and define the table's shape, `cast_failures` is a measurement, and
     * `inferred_type` is the record of what we originally proposed — which is what
     * makes "put it back" possible and must survive the owner overriding it.
     */
    public const EDITABLE = ['type', 'role', 'label', 'description', 'date_format'];

    /**
     * Apply a set of per-column edits.
     *
     * @param  array<int|string, array<string, mixed>>  $edits  column id => attributes
     * @return array{changed: int, rebuild: bool, rebuild_columns: array<int, string>}
     */
    public function apply(BiDataset $dataset, array $edits): array
    {
        /** @var array<int, BiColumn> $columns */
        $columns = $dataset->columns()->get()->keyBy('id')->all();

        $changed = 0;
        $rebuildColumns = [];

        DB::transaction(function () use ($columns, $edits, &$changed, &$rebuildColumns) {
            foreach ($edits as $id => $attributes) {
                $column = $columns[(int) $id] ?? null;

                // A column id that does not belong to this dataset is silently
                // ignored rather than erroring: the lookup is scoped to the
                // dataset's own columns, so an id from elsewhere simply is not
                // found — which is the same protection the query layer relies on.
                if ($column === null) {
                    continue;
                }

                $dirty = $this->applyToColumn($column, $attributes);

                if ($dirty === []) {
                    continue;
                }

                $changed++;

                // Only a change to the physical shape of the column forces a
                // rebuild. See the class docblock.
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

    /**
     * Whether a proposed set of edits WOULD rebuild, without applying anything.
     *
     * Used to warn the owner before they commit, so "this will reload 400,000 rows"
     * is something they read before clicking rather than after.
     *
     * @param  array<int|string, array<string, mixed>>  $edits
     */
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

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, string> The attribute names that actually changed.
     */
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

        // The date pattern lives inside `format`, and changing it changes how every
        // value in the column is read — so it rebuilds, exactly like a type change.
        // This is the fix for the one case inference genuinely cannot settle:
        // `03.04.2026` being April or March.
        $format = $column->format ?? [];
        $newFormat = $this->resolveDateFormat($attributes, $column);

        if ($newFormat !== ($format['date_format'] ?? null)) {
            if ($newFormat === null) {
                unset($format['date_format']);
            } else {
                $format['date_format'] = $newFormat;
                // The owner has now told us the order explicitly, so it is no longer
                // an assumption and the warning must stop being shown.
                $format['date_ambiguous'] = false;
                $format['date_order'] = str_starts_with($newFormat, 'd') ? 'dmy' : 'mdy';
            }

            $column->format = $format === [] ? null : $format;
            $dirty[] = 'format';
        }

        // A type the column can no longer be indexed under must not keep its index:
        // re-typing free text to a number, or a dimension to a measure, changes
        // whether an index is worth having at all.
        if (array_intersect($dirty, ['type', 'role']) !== []) {
            $column->indexed = $this->shouldIndex($column);
        }

        if ($dirty !== []) {
            $column->save();
        }

        return $dirty;
    }

    /**
     * Whether a column, as now typed and rolled, still earns an index.
     *
     * Mirrors SchemaInferrer's rule without its dataset-wide budget: an owner
     * re-typing one column is not an occasion to re-rank every other one.
     */
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

    /**
     * The date pattern the edits ask for, validated against the closed set.
     *
     * A pattern is only meaningful on a temporal column, and only one of the
     * patterns the voter itself can produce is accepted — an arbitrary format string
     * from a request would be a value reaching `createFromFormat`, and while that is
     * not injectable it is a way to produce a column nothing can parse.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveDateFormat(array $attributes, BiColumn $column): ?string
    {
        if (! array_key_exists('date_format', $attributes)) {
            return $column->format['date_format'] ?? null;
        }

        $requested = $attributes['date_format'];

        // The resulting type decides whether a pattern applies at all: re-typing a
        // date column to text must drop the pattern with it.
        $type = $this->resolveType($attributes['type'] ?? null) ?? $column->type;

        if (! $type->isTemporal() || ! is_string($requested) || $requested === '') {
            return null;
        }

        return in_array($requested, self::selectableDateFormats(), true)
            ? $requested
            : ($column->format['date_format'] ?? null);
    }

    /**
     * The date patterns the schema editor offers.
     *
     * Deliberately short. These are the readings an owner might need to CORRECT —
     * the day/month ambiguity and the two-digit year — not every format the voter
     * can recognize. Offering twenty would make the one that matters hard to find.
     *
     * @return array<int, string>
     */
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
