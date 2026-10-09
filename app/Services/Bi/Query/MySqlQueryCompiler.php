<?php

namespace App\Services\Bi\Query;

/**
 * The dialect production actually runs.
 *
 * MySQL 8.4, and the analytical tables are ours — created by
 * `DatasetMaterializer` with types from `ColumnType::define()` — so this
 * compiler, unlike the live-database one, never has to accommodate a schema it
 * did not design.
 */
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

    /**
     * The server-side ceiling, as an optimizer hint.
     *
     * A hint rather than a session variable because the runner shares Laravel's
     * connection pool with the rest of the request: `SET SESSION
     * max_execution_time` would outlive this query and silently cap an unrelated
     * one later in the same request. The hint is scoped to the statement it is
     * written on, which is exactly the lifetime wanted.
     *
     * MariaDB ignores an unrecognized hint as a comment. That is acceptable here
     * in a way it would not be for the live-database layer: these tables are on
     * OUR server, so the PHP-side guard in QueryRunner is a real backstop rather
     * than a hope, and `config('agent.bi.query.timeout_seconds')` still bounds
     * how long the request waits.
     */
    protected function selectPrefix(int $timeoutMs): string
    {
        return '/*+ MAX_EXECUTION_TIME('.$timeoutMs.') */ ';
    }

    /**
     * Date bucketing. Must agree byte-for-byte with SqliteQueryCompiler — see
     * TimeGrain, and the test that asserts both against the same dates.
     */
    protected function timeBucket(string $quotedColumn, TimeGrain $grain): string
    {
        return match ($grain) {
            TimeGrain::Day => "DATE_FORMAT({$quotedColumn}, '%Y-%m-%d')",

            // The Monday's date. WEEKDAY() is 0 for Monday regardless of the
            // server's `default_week_format` or `lc_time_names`, which DAYOFWEEK()
            // and WEEK() are both sensitive to — a bucket that moved with a
            // server variable would be a bug nobody could reproduce locally.
            TimeGrain::Week => "DATE_FORMAT(DATE_SUB({$quotedColumn}, INTERVAL WEEKDAY({$quotedColumn}) DAY), '%Y-%m-%d')",

            TimeGrain::Month => "DATE_FORMAT({$quotedColumn}, '%Y-%m-01')",
            TimeGrain::Quarter => "CONCAT(DATE_FORMAT({$quotedColumn}, '%Y'), '-Q', QUARTER({$quotedColumn}))",
            TimeGrain::Year => "DATE_FORMAT({$quotedColumn}, '%Y')",
        };
    }
}
