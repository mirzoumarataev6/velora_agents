<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Services\Bi\Schema\ColumnType;

final class Measure
{
    public function __construct(
        public readonly Aggregate $aggregate,
        public readonly ?BiColumn $column = null,
        public readonly int $position = 0,
    ) {}

    public function alias(): string
    {
        return 'm'.$this->position;
    }

    public function labelKey(): string
    {
        return 'bi.aggregates.'.$this->aggregate->value;
    }

    public function columnLabel(): ?string
    {
        return $this->column?->displayName();
    }

    public function resultType(): ColumnType
    {
        return $this->aggregate->resultType($this->column?->type);
    }

    public function fingerprint(): string
    {
        return 'm:'.$this->aggregate->value.':'.($this->column?->getKey() ?? '-');
    }
}
