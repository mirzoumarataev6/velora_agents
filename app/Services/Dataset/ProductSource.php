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
