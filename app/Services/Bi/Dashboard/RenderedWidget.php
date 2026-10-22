<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiWidget;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryResult;

final class RenderedWidget
{
    private function __construct(
        public readonly BiWidget $widget,
        public readonly ?QueryResult $result,
        public readonly ?string $errorCode,
        public readonly ?string $errorDetail,
        public readonly bool $transient,

        public readonly array $overriddenFilters,
        public readonly ?float $trend = null,
    ) {}

    public static function ok(BiWidget $widget, QueryResult $result, array $overridden = [], ?float $trend = null): self
    {
        return new self($widget, $result, null, null, false, $overridden, $trend);
    }

    public static function fromError(BiWidget $widget, BiQueryException $e): self
    {
        return new self($widget, null, $e->reason, $e->detail(), $e->isTransient(), []);
    }

    public function failed(): bool
    {
        return $this->errorCode !== null;
    }

    public function errorKey(): ?string
    {
        return $this->errorCode === null ? null : 'bi.errors.'.$this->errorCode;
    }

    public function isEmpty(): bool
    {
        return ! $this->failed() && ($this->result === null || $this->result->isEmpty());
    }

    public function cached(): bool
    {
        return $this->result?->cached ?? false;
    }

    public function durationMs(): int
    {
        return $this->result?->durationMs ?? 0;
    }
}
