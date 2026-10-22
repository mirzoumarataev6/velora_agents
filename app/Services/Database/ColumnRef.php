<?php

namespace App\Services\Database;

final class ColumnRef
{
    public function __construct(
        public readonly string $column,
        public readonly string $alias = '',
    ) {}

    public static function make(self|array|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return new self($value);
        }

        return new self(
            column: (string) ($value['column'] ?? ''),
            alias: (string) ($value['alias'] ?? ''),
        );
    }

    public function key(): string
    {
        return $this->alias.'.'.$this->column;
    }

    public function isBase(): bool
    {
        return $this->alias === '';
    }
}
