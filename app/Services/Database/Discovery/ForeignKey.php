<?php

namespace App\Services\Database\Discovery;

/**
 * One single-column relationship between two tables.
 *
 * Two things can produce one:
 *
 *  - the customer's schema DECLARED it (a real FOREIGN KEY constraint), which is
 *    a fact and is trusted accordingly;
 *  - discovery INFERRED it from a naming convention (`category_id` next to a
 *    `categories` table), which is a guess and is labelled as one so the owner
 *    sees a lower confidence and the UI can say why.
 *
 * `$declared` is the difference, and it is carried all the way to the screen
 * rather than being flattened away here. Both kinds are validated identically
 * before anything is joined — an inferred key is less certain, not less checked.
 */
final class ForeignKey
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $foreignTable,
        public readonly string $foreignColumn,
        public readonly bool $declared = true,
    ) {}

    /**
     * Build from a driver's foreignKeys() row, dropping composite keys.
     *
     * Composites are skipped rather than half-followed: joining on one column of
     * a two-column key silently returns the wrong rows, which is worse than not
     * offering the join at all. The caller groups rows by `constraint` and hands
     * over only the ones that appear once.
     *
     * @param  array{table: string, column: string, foreign_table: string, foreign_column: string}  $row
     */
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

    /**
     * @param  array<string, mixed>  $data
     */
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

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    }

    /**
     * @return array<string, mixed>
     */
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
