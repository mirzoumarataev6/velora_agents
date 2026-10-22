<?php

namespace App\Services\Database\Discovery;

final class ForeignKey
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $foreignTable,
        public readonly string $foreignColumn,
        public readonly bool $declared = true,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            table: (string) ($row['table'] ?? ''),
            column: (string) ($row['column'] ?? ''),
            foreignTable: (string) ($row['foreign_table'] ?? ''),
            foreignColumn: (string) ($row['foreign_column'] ?? ''),
            declared: true,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            table: self::text($data, 'table'),
            column: self::text($data, 'column'),
            foreignTable: self::text($data, 'foreign_table'),
            foreignColumn: self::text($data, 'foreign_column'),
            declared: (bool) ($data['declared'] ?? true),
        );
    }

    private static function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'column' => $this->column,
            'foreign_table' => $this->foreignTable,
            'foreign_column' => $this->foreignColumn,
            'declared' => $this->declared,
        ];
    }
}
