<?php

namespace App\Services\Database\Discovery;

use App\Services\Database\ColumnRef;
use App\Services\Database\LiveProductSource;

final class DiscoveryProposal
{
    public function __construct(
        public readonly string $table,
        public readonly string $type,
        public readonly string $confidence,

        public readonly array $reasons,
        public readonly ColumnRoles $roles,
        public readonly JoinPlan $joins,
        public readonly ?int $rowCount = null,

        public readonly array $notes = [],
    ) {}

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

    public function joinSummary(): array
    {
        $summary = [];

        foreach ($this->joins->joins as $join) {
            foreach ($join->columns as $field => $column) {

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

    public function hasInferredRelations(): bool
    {
        return $this->joins->hasInferredKeys();
    }

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
