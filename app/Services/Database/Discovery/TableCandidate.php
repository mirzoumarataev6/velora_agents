<?php

namespace App\Services\Database\Discovery;

/**
 * One table that might be where the products live, and WHY we think so.
 *
 * The reasons are the point. A ranked list with no explanation asks the owner to
 * trust a number; a list that says "called `products`, has a text `Название` and
 * a decimal `Цена`, and `stocks` points at it" asks them to check something they
 * actually know the answer to. They are emitted as codes plus parameters rather
 * than sentences so the tab can render them in the owner's own language.
 */
final class TableCandidate
{
    /** Backed by declared foreign keys and a matching table name. */
    public const HIGH = 'high';

    /** Names and types agree, but nothing in the schema confirms it. */
    public const MEDIUM = 'medium';

    /** Recognized by column shape alone, or with a close runner-up. */
    public const LOW = 'low';

    /**
     * @param  array<int, array{code: string, params?: array<string, mixed>}>  $reasons
     */
    public function __construct(
        public readonly string $table,
        public readonly string $type,
        public readonly int $score,
        public readonly string $confidence,
        public readonly array $reasons,
        public readonly ColumnRoles $roles,
        public readonly ?int $rowCount = null,
        /** Set when the product NAME is not on this table but on a related one. */
        public readonly ?string $nameFrom = null,
        /**
         * Required fields the deterministic pass could not resolve — here or
         * one safe hop away. A candidate with anything in here is NOT offered:
         * it is the input to the model fallback, and either comes back complete
         * or does not come back.
         *
         * @var array<int, string>
         */
        public readonly array $missing = [],
    ) {}

    public function isView(): bool
    {
        return $this->type === 'view';
    }

    /**
     * The same candidate at a different confidence — used when the ranking as a
     * whole turns out to be close, which is a fact about the LIST rather than
     * about any one table in it.
     */
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

    /**
     * @return array<string, mixed>
     */
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
