<?php

namespace App\Services\Bi\Query;

/**
 * A finished statement and the values to bind to it.
 *
 * Carried as a type rather than a tuple so the probe row below can be explained
 * once, in the place that produces it, instead of being rediscovered by whoever
 * next wonders why the SQL says 101 when the spec said 100.
 */
final class CompiledQuery
{
    /**
     * @param  array<int, mixed>  $bindings
     * @param  int  $pageSize  Rows the CALLER asked for.
     * @param  int  $fetchLimit  Rows the SQL asks for: $pageSize + 1.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly int $pageSize,
        public readonly int $fetchLimit,
    ) {}

    /**
     * Whether a result set of this size means there is another page.
     *
     * The probe row is why pagination here costs nothing extra. The obvious
     * alternative — a second `SELECT COUNT(*)` over the same filters — doubles
     * the work for a question nobody asked: a chart needs to know whether to draw
     * a "next" button, not how many pages exist. On a two-million-row dataset
     * that second scan is the slower half of the request, and it can disagree
     * with the first if a rematerialization lands between them.
     */
    public function hasMore(int $returned): bool
    {
        return $returned > $this->pageSize;
    }
}
