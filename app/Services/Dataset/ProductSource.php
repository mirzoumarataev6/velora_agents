<?php

namespace App\Services\Dataset;

interface ProductSource
{
    // Возвращает ключ, идентифицирующий этот источник товаров
    public function key(): string;

    // Сообщает, есть ли в источнике товары
    public function hasProducts(): bool;

    // Сообщает, ведётся ли в источнике учёт остатков на складе
    public function tracksStock(): bool;

    // Возвращает товары, подходящие под фильтры, с ограничением по количеству
    public function fetch(array $filters, int $limit): array;
}
