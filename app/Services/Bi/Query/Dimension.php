<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;
use App\Services\Bi\Schema\ColumnType;

final class Dimension
{
    public function __construct(
        public readonly BiColumn $column,
        public readonly ?TimeGrain $grain = null,
        public readonly int $position = 0,
    ) {}

    public function alias(): string
    {
        return 'd'.$this->position;
    }

    public function label(): string
    {
        return $this->column->displayName();
    }

    public function isBucketed(): bool
    {
        return $this->grain !== null;
    }

    public function resultType(): ColumnType
    {
        return $this->isBucketed() ? ColumnType::Text : $this->column->type;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function fingerprint(): string
    {
        return 'd:'.$this->column->getKey().':'.($this->grain?->value ?? '-');
    }
}
