<?php

namespace App\Services\Bi\Query;

class MySqlQueryCompiler extends QueryCompiler
{
    public function engine(): string
    {
        return 'mysql';
    }

    protected function quoteChar(): string
    {
        return '`';
    }

    protected function selectPrefix(int $timeoutMs): string
    {
        return '/*+ MAX_EXECUTION_TIME('.$timeoutMs.') */ ';
    }

    protected function timeBucket(string $quotedColumn, TimeGrain $grain): string
    {
        return match ($grain) {
            TimeGrain::Day => "DATE_FORMAT({$quotedColumn}, '%Y-%m-%d')",

            TimeGrain::Week => "DATE_FORMAT(DATE_SUB({$quotedColumn}, INTERVAL WEEKDAY({$quotedColumn}) DAY), '%Y-%m-%d')",

            TimeGrain::Month => "DATE_FORMAT({$quotedColumn}, '%Y-%m-01')",
            TimeGrain::Quarter => "CONCAT(DATE_FORMAT({$quotedColumn}, '%Y'), '-Q', QUARTER({$quotedColumn}))",
            TimeGrain::Year => "DATE_FORMAT({$quotedColumn}, '%Y')",
        };
    }
}
