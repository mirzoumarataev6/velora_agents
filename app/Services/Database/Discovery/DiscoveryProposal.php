<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\ColumnRef;
use App\Services\Database\LiveProductSource;

/**
 * One complete answer to "where are the products?", ready for the owner to
 * confirm or reject.
 *
 * A candidate says which table looks right; a proposal says what would actually
 * be read — including the tables joined alongside it and which field each
 * contributes. It is the thing the confirmation screen renders, the thing the
 * preview is built from, and, if confirmed, the thing that becomes the stored
 * mapping and join plan.
 *
 * It is deliberately inert. Nothing here executes; nothing here is stored until
 * the owner says so.
 */
final class DiscoveryProposal
{
    public function __construct(
        public readonly string $table,
        public readonly string $type,
        public readonly string $confidence,
        /** @var array<int, array{code: string, params?: array<string, mixed>}> */
        public readonly array $reasons,
        public readonly ColumnRoles $roles,
        public readonly JoinPlan $joins,
        public readonly ?int $rowCount = null,
        /**
         * Things the owner has to be told that are not reasons FOR this table —
         * chiefly a stock table we found and did not read. An absent stock
         * column reads as "this shop has no stock data", so the difference
         * between "there is none" and "we would not guess at it" has to be said
         * out loud.
         *
         * @var array<int, array{code: string, params?: array<string, mixed>}>
         */
        public readonly array $notes = [],
    ) {}

    /**
     * Every field the agent would end up seeing, field => ColumnRef.
     *
     * The product table's own columns win over anything a join offers, which is
     * the same rule the search applies at query time.
     *
     * @return array<string, ColumnRef>
     */
    public function columns(): array
    {
        $columns = [];

        foreach (LiveProductSource::FIELDS as $field) {
            $column = $this->roles->column($field);

            if ($column !== null) {
                $columns[$field] = new ColumnRef($column);
            }
        }

        foreach ($this->joins->fields() as $field => $source) {
            if (! isset($columns[$field]) && in_array($field, LiveProductSource::FIELDS, true)) {
                $columns[$field] = new ColumnRef($source['column'], $source['alias']);
            }
        }

        return $columns;
    }

    /**
     * What the plain-language summary is built from: which field comes from
     * which table.
     *
     * `aggregated` is part of it because the owner is entitled to know that the
     * number they are about to approve is a TOTAL: a shop with three warehouses
     * sees one stock figure per product, and whether that is right is their call
     * to make knowingly.
     *
     * @return array<int, array{field: string, table: string, declared: bool, aggregated: bool}>
     */
    public function joinSummary(): array
    {
        $summary = [];

        foreach ($this->joins->joins as $join) {
            foreach ($join->columns as $field => $column) {
                // Only the fields this join actually ends up supplying: a field
                // the product table already has is not "pulled from" anywhere.
                if (! $this->roles->has($field)) {
                    $summary[] = [
                        'field' => $field,
                        'table' => $join->table,
                        'declared' => $join->declared,
                        'aggregated' => $join->isAggregated(),
                    ];
                }
            }
        }

        return $summary;
    }

    public function isView(): bool
    {
        return $this->type === 'view';
    }

    /**
     * Whether any part of this proposal rests on a guess rather than something
     * the schema declares.
     */
    public function hasInferredRelations(): bool
    {
        return $this->joins->hasInferredKeys();
    }

    /**
     * The shape the tab renders and the confirm endpoint echoes back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'type' => $this->type,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'notes' => $this->notes,
            'mapping' => $this->roles->toMapping(),
            'joins' => $this->joins->toArray(),
            'join_summary' => $this->joinSummary(),
            'fields' => array_keys($this->columns()),
            'row_count' => $this->rowCount,
            'inferred_relations' => $this->hasInferredRelations(),
        ];
    }
}
