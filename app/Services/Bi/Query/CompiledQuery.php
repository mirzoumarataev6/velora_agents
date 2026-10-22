<?php

namespace App\Services\Bi\Query;

final class CompiledQuery
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly int $pageSize,
        public readonly int $fetchLimit,
    ) {}

    public function hasMore(int $returned): bool
    {
        return $returned > $this->pageSize;
    }
}
