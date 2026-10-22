<?php

namespace App\Services\Database\Discovery;

final class TableCandidate
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    public function __construct(
        public readonly string $table,
        public readonly string $type,
        public readonly int $score,
        public readonly string $confidence,
        public readonly array $reasons,
        public readonly ColumnRoles $roles,
        public readonly ?int $rowCount = null,

        public readonly ?string $nameFrom = null,

        public readonly array $missing = [],
    ) {}

    public function isView(): bool
    {
        return $this->type === 'view';
    }

    public function withConfidence(string $confidence): self
    {
        return new self(
            $this->table,
            $this->type,
            $this->score,
            $confidence,
            $this->reasons,
            $this->roles,
            $this->rowCount,
            $this->nameFrom,
            $this->missing,
        );
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'type' => $this->type,
            'score' => $this->score,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'mapping' => $this->roles->toMapping(),
            'row_count' => $this->rowCount,
            'name_from' => $this->nameFrom,
        ];
    }
}
