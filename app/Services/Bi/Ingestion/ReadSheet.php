<?php

namespace App\Services\Bi\Ingestion;

use Closure;
use Generator;

final class ReadSheet
{
    public function __construct(
        public readonly string $name,
        public readonly int $index,
        private readonly Closure $rows,
    ) {}

    public function rows(): Generator
    {
        return ($this->rows)();
    }

    public function isGenericName(): bool
    {
        $folded = mb_strtolower(trim($this->name));

        return $folded === ''
            || (bool) preg_match('/^(sheet|worksheet|лист|варақ|varaq)\s*\d*$/u', $folded);
    }
}
