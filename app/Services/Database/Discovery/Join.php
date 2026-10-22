<?php

namespace App\Services\Database\Discovery;

final class Join
{
    public const ALIAS_PATTERN = '/^j[0-9]{1,2}$/';

    public const AGGREGATES = ['sum'];

    public function __construct(
        public readonly string $table,
        public readonly string $alias,
        public readonly string $localAlias,
        public readonly string $localColumn,
        public readonly string $foreignColumn,
        public readonly array $columns,
        public readonly bool $declared = true,
        public readonly ?string $aggregate = null,
    ) {}

    public function isAggregated(): bool
    {
        return $this->aggregate !== null;
    }

    public static function fromArray(array $data): self
    {
        $columns = [];
        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $field => $column) {
            if (is_string($field) && is_string($column)) {
                $columns[$field] = $column;
            }
        }

        $aggregate = self::text($data, 'aggregate');

        return new self(
            table: self::text($data, 'table'),
            alias: self::text($data, 'alias'),
            localAlias: self::text($data, 'local_alias'),
            localColumn: self::text($data, 'local_column'),
            foreignColumn: self::text($data, 'foreign_column'),
            columns: $columns,
            declared: (bool) ($data['declared'] ?? true),

            aggregate: in_array($aggregate, self::AGGREGATES, true) ? $aggregate : null,
        );
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'alias' => $this->alias,
            'local_alias' => $this->localAlias,
            'local_column' => $this->localColumn,
            'foreign_column' => $this->foreignColumn,
            'columns' => $this->columns,
            'declared' => $this->declared,
            'aggregate' => $this->aggregate,
        ];
    }

    public function isDirect(): bool
    {
        return $this->localAlias === '';
    }

    private static function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    }
}
