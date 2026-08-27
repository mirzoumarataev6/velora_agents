<?php

namespace App\Services\Database\Discovery;

/**
 * Everything one scan of a customer's database produced.
 *
 * `ambiguous` is not a failure state. Two tables that both look like the catalog
 * — retail and wholesale, a table and the view built over it — is a real
 * situation with a right answer only the owner knows, and this says so rather
 * than picking. The tab shows both.
 */
final class DiscoveryResult
{
    /** Decided by declared facts and name heuristics alone. */
    public const DETERMINISTIC = 'deterministic';

    /** A model was asked to break a tie, and its answer survived validation. */
    public const LLM_ASSISTED = 'llm_assisted';

    /**
     * @param  array<int, DiscoveryProposal>  $proposals  Best first.
     */
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

    /**
     * @return array<string, mixed>
     */
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
