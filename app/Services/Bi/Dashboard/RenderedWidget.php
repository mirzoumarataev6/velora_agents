<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiWidget;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryResult;

/**
 * A widget and whatever came back when it was asked its question — which may be
 * an error, and that is the normal case rather than the exceptional one.
 *
 * WHY A FAILED WIDGET IS A VALUE RATHER THAN AN EXCEPTION: a board holds up to
 * forty cards over several datasets, any of which can be mid-rebuild, deleted,
 * or re-typed since the card was saved. If one failing card threw, the whole
 * board would 500 — the owner would lose thirty-nine working charts because one
 * referenced a column that no longer exists. So the renderer catches per card,
 * and the page draws the rest.
 *
 * `errorCode` is a classified `BiQueryException` reason, so the card renders
 * the same translated sentence the rest of the module uses, and `transient`
 * decides whether the card offers a retry at all.
 */
final class RenderedWidget
{
    private function __construct(
        public readonly BiWidget $widget,
        public readonly ?QueryResult $result,
        public readonly ?string $errorCode,
        public readonly ?string $errorDetail,
        public readonly bool $transient,
        /** @var array<int, string> Board filters this card is ignoring. */
        public readonly array $overriddenFilters,
        public readonly ?float $trend = null,
    ) {}

    /**
     * @param  array<int, string>  $overridden
     */
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

    /** The translated sentence the card shows in place of a chart. */
    public function errorKey(): ?string
    {
        return $this->errorCode === null ? null : 'bi.errors.'.$this->errorCode;
    }

    /**
     * Whether the card has a shape but no data — distinct from failing.
     *
     * A chart of a filtered-to-nothing dataset is not broken, and telling the
     * owner "no rows match these filters" is a different and more useful
     * sentence than an error.
     */
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
