<?php

namespace App\Services\Dataset;

/**
 * Where one agent's product facts come from.
 *
 * Two implementations exist — the uploaded catalog (DatasetProductSource) and
 * the business's own live database (LiveProductSource) — and ProductSearchService
 * cannot tell them apart. That is the point: the `search_products` tool
 * definition, its argument validation, its row cap and the way results are
 * phrased are identical whatever the agent is plugged into, so switching source
 * changes where the numbers come from and nothing else about how the agent
 * behaves.
 */
interface ProductSource
{
    /** 'dataset' or 'database' — for logs and the Playground's diagnostics. */
    public function key(): string;

    /**
     * Whether this source has anything worth offering the tool for.
     *
     * Must be cheap: it is called on every incoming message, before the prompt
     * is built. The live source answers from its stored configuration rather
     * than by dialling the customer's server.
     */
    public function hasProducts(): bool;

    /**
     * Whether this source can answer "is it in stock" at all.
     *
     * False for a catalog whose file had no stock column, and for a live table
     * whose mapping has none or maps a non-numeric one. The distinction matters
     * because the alternative is silent and total: `stock > 0` is false for
     * every NULL, so applying the filter to a source that tracks nothing
     * excludes the entire catalog and reports "we have none of those" about a
     * shop that has ten.
     *
     * Must be cheap. It is consulted once per search, and only when the model
     * actually asked to filter by stock.
     */
    public function tracksStock(): bool;

    /**
     * Run one search and return presented product rows.
     *
     * @param  array<string, mixed>  $filters  Already normalized by ProductSearchService.
     * @param  int  $limit  Already clamped to ProductSearchService::MAX_LIMIT.
     * @return array<int, array<string, mixed>>
     *
     * @throws \Throwable when the source cannot be read; the caller degrades gracefully.
     */
    public function fetch(array $filters, int $limit): array;
}
