<?php

namespace App\Services\Bi\Query;

use App\Services\Bi\Schema\ColumnType;

/**
 * What a query returned, plus the handful of facts the UI needs to render it
 * honestly.
 *
 * `cached` and `durationMs` are part of the result rather than a logging detail
 * on purpose. A dashboard that has just been told "no rows" should be able to
 * say whether it is looking at a fresh answer or a minute-old one, and a slow
 * tile should be able to say so rather than just feeling slow. This module's
 * house rule is to tell the owner what something cost.
 */
final class QueryResult
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Keyed by output alias (`d0`, `m1`).
     * @param  array<int, array{alias: string, kind: string, label: string, type: string, grain: string|null, aggregate: string|null}>  $columns
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $columns,
        public readonly bool $hasMore,
        public readonly int $offset,
        public readonly int $pageSize,
        public readonly int $durationMs,
        public readonly bool $cached = false,
    ) {}

    /**
     * Describe a spec's output columns, so the caller can label a chart without
     * re-reading `bi_columns`.
     *
     * Labels are the owner's text and travel as DATA here, exactly as they do
     * everywhere else in the module — the alias is the identifier, the label is
     * what a human reads, and the two never swap roles.
     *
     * @return array<int, array{alias: string, kind: string, label: string, type: string, grain: string|null, aggregate: string|null}>
     */
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
                // The aggregate's own name is NOT baked in here: it is translated
                // at render time through `labelKey()`, because this product swaps
                // locale in place without a reload and a sentence built in a
                // service cannot follow it.
                'label' => $measure->columnLabel() ?? '',
                'type' => $measure->resultType()->value,
                'grain' => null,
                'aggregate' => $measure->aggregate->value,
            ];
        }

        return $columns;
    }

    /** An empty page, for the windows where querying is the wrong answer. */
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

    /**
     * The shape that goes into the cache and comes back out of it.
     *
     * Plain arrays rather than a serialized object: a cached entry outlives the
     * deploy that wrote it, and `unserialize()` of a class whose constructor
     * signature has since changed is a fatal error in a cache hit — the worst
     * possible place for one, because clearing the cache is the only cure and
     * nothing says that is what is wrong.
     *
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $cached
     */
    public static function fromCache(array $cached): self
    {
        return new self(
            rows: $cached['rows'] ?? [],
            columns: $cached['columns'] ?? [],
            hasMore: (bool) ($cached['has_more'] ?? false),
            offset: (int) ($cached['offset'] ?? 0),
            pageSize: (int) ($cached['page_size'] ?? 0),
            // The ORIGINAL cost, kept rather than reported as zero: "this tile
            // takes 4 seconds to compute" stays true on a cache hit, and is the
            // number worth seeing when deciding what to optimize.
            durationMs: (int) ($cached['duration_ms'] ?? 0),
            cached: true,
        );
    }

    /** Values of one output column, in row order — what a chart series wants. */
    public function column(string $alias): array
    {
        return array_map(fn (array $row) => $row[$alias] ?? null, $this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** @return array<int, string> */
    public function aliases(): array
    {
        return array_column($this->columns, 'alias');
    }

    /** The result type of one output column, for formatting. */
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
