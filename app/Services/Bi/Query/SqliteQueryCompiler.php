<?php

namespace App\Services\Bi\Query;

class SqliteQueryCompiler extends QueryCompiler
{
    public function engine(): string
    {
        return 'sqlite';
    }

    protected function quoteChar(): string
    {
        return '"';
    }

    protected function selectPrefix(int $timeoutMs): string
    {
        return '';
    }

    protected function timeBucket(string $quotedColumn, TimeGrain $grain): string
    {
        return match ($grain) {
            TimeGrain::Day => "strftime('%Y-%m-%d', {$quotedColumn})",

            TimeGrain::Week => "strftime('%Y-%m-%d', {$quotedColumn}, '-6 days', 'weekday 1')",

            TimeGrain::Month => "strftime('%Y-%m-01', {$quotedColumn})",

            TimeGrain::Quarter => "strftime('%Y', {$quotedColumn}) || '-Q' || "
                ."((CAST(strftime('%m', {$quotedColumn}) AS INTEGER) + 2) / 3)",

            TimeGrain::Year => "strftime('%Y', {$quotedColumn})",
        };
    }
}
