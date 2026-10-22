<?php

namespace App\Services\Bi\Query;

use App\Services\Bi\Schema\ColumnType;

final class QueryResult
{
    public function __construct(
        public readonly array $rows,
        public readonly array $columns,
        public readonly bool $hasMore,
        public readonly int $offset,
        public readonly int $pageSize,
        public readonly int $durationMs,
        public readonly bool $cached = false,
    ) {}

    public static function describe(QuerySpec $spec): array
    {
        $columns = [];

        foreach ($spec->dimensions as $dimension) {
            $columns[] = [
                'alias' => $dimension->alias(),
                'kind' => 'dimension',
                'label' => $dimension->label(),
                'type' => $dimension->resultType()->value,
                'grain' => $dimension->grain?->value,
                'aggregate' => null,
            ];
        }

        foreach ($spec->measures as $measure) {
            $columns[] = [
                'alias' => $measure->alias(),
                'kind' => 'measure',

                'label' => $measure->columnLabel() ?? '',
                'type' => $measure->resultType()->value,
                'grain' => null,
                'aggregate' => $measure->aggregate->value,
            ];
        }

        return $columns;
    }

    public static function empty(QuerySpec $spec): self
    {
        return new self(
            rows: [],
            columns: self::describe($spec),
            hasMore: false,
            offset: $spec->offset,
            pageSize: $spec->limit,
            durationMs: 0,
        );
    }

    public function toCache(): array
    {
        return [
            'rows' => $this->rows,
            'columns' => $this->columns,
            'has_more' => $this->hasMore,
            'offset' => $this->offset,
            'page_size' => $this->pageSize,
            'duration_ms' => $this->durationMs,
        ];
    }

    public static function fromCache(array $cached): self
    {
        return new self(
            rows: $cached['rows'] ?? [],
            columns: $cached['columns'] ?? [],
            hasMore: (bool) ($cached['has_more'] ?? false),
            offset: (int) ($cached['offset'] ?? 0),
            pageSize: (int) ($cached['page_size'] ?? 0),

            durationMs: (int) ($cached['duration_ms'] ?? 0),
            cached: true,
        );
    }

    public function column(string $alias): array
    {
        return array_map(fn (array $row) => $row[$alias] ?? null, $this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function aliases(): array
    {
        return array_column($this->columns, 'alias');
    }

    public function typeOf(string $alias): ?ColumnType
    {
        foreach ($this->columns as $column) {
            if ($column['alias'] === $alias) {
                return ColumnType::tryFrom($column['type']);
            }
        }

        return null;
    }
}
