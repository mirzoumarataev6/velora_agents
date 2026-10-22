<?php

namespace App\Services\Bi\Query;

final class Sort
{
    public function __construct(
        public readonly string $alias,
        public readonly SortDirection $direction = SortDirection::Asc,
    ) {}

    public function fingerprint(): string
    {
        return 's:'.$this->alias.':'.$this->direction->value;
    }
}
