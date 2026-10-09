<?php

namespace App\Services\Bi\Query;

class ExternalPostgresQueryCompiler extends QueryCompiler
{
    use ExternalColumns;

    public function engine(): string
    {
        return 'pgsql';
    }

    protected function quoteChar(): string
    {
        return '"';
    }

    protected function selectPrefix(int $timeoutMs): string
    {
        // The customer's read-only session sets statement_timeout; a hint
        // would be MySQL-only and a session mutation here would outlive a query.
        return '';
    }

    protected function orderTerm(string $quotedAlias, SortDirection $direction): string
    {
        // PostgreSQL permits a SELECT alias as a complete ORDER BY term, but
        // not inside `alias IS NULL` (the portable MySQL/SQLite spelling).
        return $quotedAlias.' '.$direction->sql().' NULLS LAST';
    }

    protected function timeBucket(string $quotedColumn, TimeGrain $grain): string
    {
        return match ($grain) {
            TimeGrain::Day => "to_char({$quotedColumn}, 'YYYY-MM-DD')",
            TimeGrain::Week => "to_char(date_trunc('week', {$quotedColumn}), 'YYYY-MM-DD')",
            TimeGrain::Month => "to_char({$quotedColumn}, 'YYYY-MM-01')",
            TimeGrain::Quarter => "to_char({$quotedColumn}, 'YYYY') || '-Q' || extract(quarter from {$quotedColumn})::integer",
            TimeGrain::Year => "to_char({$quotedColumn}, 'YYYY')",
        };
    }
}
