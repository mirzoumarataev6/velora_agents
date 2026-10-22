<?php

namespace App\Services\Bi\Query;

use App\Models\BiColumn;

final class Filter
{
    public function __construct(
        public readonly BiColumn $column,
        public readonly FilterOperator $operator,
        public readonly array $values = [],
    ) {}

    public function bindings(): array
    {
        if (! $this->operator->isPattern()) {
            return array_values($this->values);
        }

        return [$this->operator->pattern((string) ($this->values[0] ?? ''))];
    }

    public function fingerprint(): string
    {
        return 'f:'.$this->column->getKey().':'.$this->operator->value
            .':'.md5(serialize(array_values($this->values)));
    }
}
