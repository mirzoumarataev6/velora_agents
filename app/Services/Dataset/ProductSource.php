<?php

namespace App\Services\Dataset;

interface ProductSource
{
    public function key(): string;

    public function hasProducts(): bool;

    public function tracksStock(): bool;

    public function fetch(array $filters, int $limit): array;
}
