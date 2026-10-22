<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Models\BiDataset;

final class QuerySpec
{
    public readonly array $dimensions;

    public readonly array $measures;

    public readonly array $filters;

    public readonly array $sorts;

    public readonly int $limit;

    public readonly int $offset;

    public function __construct(
        public readonly BiDataset $dataset,
        array $dimensions = [],
        array $measures = [],
        array $filters = [],
        array $sorts = [],
        int $limit = 100,
        int $offset = 0,
        public readonly bool $detail = false,
    ) {
        if ($detail && ($dimensions === [] || $measures !== [] || collect($dimensions)->contains(fn (Dimension $d) => $d->grain !== null))) {
            throw new BiQueryException(BiQueryException::WIDGET_SHAPE, 'A detail table selects raw columns without measures or time buckets.');
        }
        if ($dimensions === [] && $measures === []) {
            throw new BiQueryException(
                BiQueryException::EMPTY_SPEC,
                'A query must select at least one dimension or one measure.'
            );
        }

        $maxFields = self::maxFields();

        if (count($dimensions) + count($measures) > $maxFields) {
            throw new BiQueryException(
                BiQueryException::TOO_MANY_FIELDS,
                'A query may select at most '.$maxFields.' fields; this one selects '
                    .(count($dimensions) + count($measures)).'.'
            );
        }

        $indexed = [];
        foreach (array_values($dimensions) as $i => $dimension) {
            $this->assertOwned($dimension->column, 'dimension');

            if ($dimension->isBucketed() && ! $dimension->column->type->isTemporal()) {
                throw new BiQueryException(
                    BiQueryException::NOT_TEMPORAL,
                    "Column [{$dimension->column->physical_name}] is {$dimension->column->type->value}, "
                        .'so it cannot be bucketed by '.$dimension->grain->value.'.'
                );
            }

            $indexed[] = new Dimension($dimension->column, $dimension->grain, $i);
        }

        $this->dimensions = $indexed;

        $indexedMeasures = [];
        foreach (array_values($measures) as $i => $measure) {
            if ($measure->aggregate->needsColumn() && $measure->column === null) {
                throw new BiQueryException(
                    BiQueryException::UNKNOWN_COLUMN,
                    $measure->aggregate->value.' needs a column to read.'
                );
            }

            if ($measure->column !== null) {
                $this->assertOwned($measure->column, 'measure');

                if (! $measure->aggregate->accepts($measure->column->type)) {
                    throw new BiQueryException(
                        BiQueryException::NOT_NUMERIC,
                        $measure->aggregate->value." cannot be applied to [{$measure->column->physical_name}], "
                            ."which is {$measure->column->type->value}."
                    );
                }
            }

            $indexedMeasures[] = new Measure($measure->aggregate, $measure->column, $i);
        }

        $this->measures = $indexedMeasures;

        foreach ($filters as $filter) {
            $this->assertOwned($filter->column, 'filter');
        }

        $this->filters = array_values($filters);

        $selectable = [];
        foreach ($this->dimensions as $dimension) {
            $selectable[$dimension->alias()] = true;
        }
        foreach ($this->measures as $measure) {
            $selectable[$measure->alias()] = true;
        }

        foreach ($sorts as $sort) {
            if (! isset($selectable[$sort->alias])) {
                throw new BiQueryException(
                    BiQueryException::UNSORTABLE,
                    "Cannot sort by [{$sort->alias}]: this query does not select it."
                );
            }
        }

        $this->sorts = array_values($sorts);

        $this->limit = max(1, min($limit, self::maxRows()));
        $this->offset = max(0, min($offset, self::maxOffset()));
    }

    private function assertOwned(BiColumn $column, string $role): void
    {
        if ((int) $column->bi_dataset_id !== (int) $this->dataset->getKey()) {
            throw new BiQueryException(
                BiQueryException::UNKNOWN_COLUMN,
                "A {$role} names column [{$column->getKey()}], which belongs to dataset "
                    ."[{$column->bi_dataset_id}] rather than [{$this->dataset->getKey()}]."
            );
        }
    }

    public function isGrouped(): bool
    {
        return $this->dimensions !== [] && $this->measures !== [];
    }

    public function isScalar(): bool
    {
        return $this->dimensions === [] && $this->measures !== [];
    }

    public function fingerprint(): string
    {
        $parts = ['ds:'.$this->dataset->getKey(), 'v:'.(int) $this->dataset->version];
        $parts[] = 'detail:'.(int) $this->detail;

        foreach ($this->dimensions as $dimension) {
            $parts[] = $dimension->fingerprint();
        }
        foreach ($this->measures as $measure) {
            $parts[] = $measure->fingerprint();
        }

        $filters = array_map(fn (Filter $f) => $f->fingerprint(), $this->filters);
        sort($filters);
        $parts = array_merge($parts, $filters);

        foreach ($this->sorts as $sort) {
            $parts[] = $sort->fingerprint();
        }

        $parts[] = 'l:'.$this->limit;
        $parts[] = 'o:'.$this->offset;

        return sha1(implode('|', $parts));
    }

    public static function maxRows(): int
    {
        return max(1, (int) config('agent.bi.query.max_rows', 5000));
    }

    public static function maxOffset(): int
    {
        return self::maxRows() * 100;
    }

    public static function maxFields(): int
    {
        return max(2, (int) config('agent.bi.query.max_fields', 24));
    }
}
