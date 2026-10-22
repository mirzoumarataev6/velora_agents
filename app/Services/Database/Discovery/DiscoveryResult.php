<?php

namespace App\Services\Database\Discovery;

final class DiscoveryResult
{
    public const DETERMINISTIC = 'deterministic';

    public const LLM_ASSISTED = 'llm_assisted';

    public function __construct(
        public readonly DiscoveredSchema $schema,
        public readonly array $proposals,
        public readonly bool $ambiguous = false,
        public readonly string $method = self::DETERMINISTIC,
    ) {}

    public function isEmpty(): bool
    {
        return $this->proposals === [];
    }

    public function best(): ?DiscoveryProposal
    {
        return $this->proposals[0] ?? null;
    }

    public function proposalFor(string $table): ?DiscoveryProposal
    {
        foreach ($this->proposals as $proposal) {
            if ($proposal->table === $table) {
                return $proposal;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'proposals' => array_map(fn (DiscoveryProposal $p) => $p->toArray(), $this->proposals),
            'ambiguous' => $this->ambiguous,
            'method' => $this->method,
            'tables_scanned' => $this->schema->tableCount(),
            'declared_relations' => $this->schema->hasDeclaredForeignKeys(),
        ];
    }
}
