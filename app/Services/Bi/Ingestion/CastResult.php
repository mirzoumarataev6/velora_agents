<?php

namespace App\Services\Bi\Ingestion;

final class CastResult
{
    private function __construct(
        public readonly mixed $value,
        public readonly bool $failed,
        public readonly bool $blank,
    ) {}

    public static function ok(mixed $value): self
    {
        return new self($value, failed: false, blank: false);
    }

    public static function blank(): self
    {
        return new self(null, failed: false, blank: true);
    }

    public static function failed(): self
    {
        return new self(null, failed: true, blank: false);
    }

    public function isUsable(): bool
    {
        return ! $this->failed && ! $this->blank;
    }
}
