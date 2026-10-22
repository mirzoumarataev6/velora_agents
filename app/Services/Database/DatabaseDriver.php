<?php

namespace App\Services\Database;

use App\Services\Bi\Query\QuerySpec;

interface DatabaseDriver
{
    public function queryBi(QuerySpec $spec): array;

    public function engine(): string;

    public function testConnection(): void;

    public function listTables(): array;

    public function describeTable(string $table): array;

    public function describeSchema(): array;

    public function foreignKeys(): array;

    public function uniqueColumns(): array;

    public function approximateRowCounts(): array;

    public function query(ProductQuery $query): array;
}
