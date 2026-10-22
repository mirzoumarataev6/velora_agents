<?php

namespace App\Services\Database\Discovery;

final class ColumnRoles
{
    public const DIRECT = 'direct';

    public const TRANSLITERATED = 'transliterated';

    public const INTERPRETED = 'interpreted';

    public function __construct(
        public readonly array $columns = [],
        public readonly array $numeric = [],
        public readonly array $sources = [],
    ) {}

    public function source(string $field): string
    {
        return $this->sources[$field] ?? self::DIRECT;
    }

    public function from(string $source): array
    {
        $fields = [];

        foreach ($this->columns as $field => $column) {
            if ($this->source($field) === $source) {
                $fields[$field] = $column;
            }
        }

        return $fields;
    }

    public function with(array $columns, array $numeric, string $source): self
    {
        $merged = $this->columns;
        $mergedNumeric = $this->numeric;
        $sources = $this->sources;

        foreach ($columns as $field => $column) {
            if (isset($merged[$field])) {
                continue;
            }

            $merged[$field] = $column;
            $mergedNumeric[$field] = (bool) ($numeric[$field] ?? false);
            $sources[$field] = $source;
        }

        return new self($merged, $mergedNumeric, $sources);
    }

    public function has(string $field): bool
    {
        return isset($this->columns[$field]);
    }

    public function column(string $field): ?string
    {
        return $this->columns[$field] ?? null;
    }

    public function isNumeric(string $field): bool
    {
        return (bool) ($this->numeric[$field] ?? false);
    }

    public function hasName(): bool
    {
        return $this->has('name');
    }

    public function hasNumericPrice(): bool
    {
        return $this->has('price') && $this->isNumeric('price');
    }

    public function toMapping(): array
    {
        return $this->columns;
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }
}
