<?php

namespace App\Services\Bi\Query;

/**
 * The dialect the test suite runs, and local development with it.
 *
 * This exists so the suite can execute the REAL compiler against a REAL database
 * — `phpunit.xml` pins everything to sqlite `:memory:` — rather than asserting
 * strings a production engine never sees. Materialization is already
 * engine-agnostic (Laravel's Blueprint), so a dataset built in a test is the
 * same shape as one built in production, and the only thing standing between
 * "the test passed" and "it works" is this file agreeing with its MySQL sibling.
 *
 * Where they cannot agree, the difference is documented rather than hidden: see
 * `selectPrefix()` below and `QueryCompiler::likeClause()`.
 */
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

    /**
     * SQLite has no statement timeout, and this returns nothing.
     *
     * Not an oversight and not worth faking. `sqlite3_progress_handler` could
     * interrupt a long query, but PDO does not expose it, and `PDO::ATTR_TIMEOUT`
     * on SQLite is a BUSY-lock timeout — it waits for a writer to release a lock
     * and has nothing to do with how long a SELECT runs. Wiring it here would
     * produce a setting that looks like a timeout and never fires, which is worse
     * than an honest absence.
     *
     * So on SQLite the ceiling is the PHP-side guard in QueryRunner: it measures
     * the call and classifies an overrun as `BiQueryException::TIMEOUT` after the
     * fact. That does not stop the query — nothing available here does — but it
     * does mean the owner gets the same classified error, and the suite can test
     * the timeout path. Production is MySQL, where the hint stops it for real.
     */
    protected function selectPrefix(int $timeoutMs): string
    {
        return '';
    }

    /**
     * Date bucketing. Must agree byte-for-byte with MySqlQueryCompiler — see
     * TimeGrain, and the test that asserts both against the same dates.
     */
    protected function timeBucket(string $quotedColumn, TimeGrain $grain): string
    {
        return match ($grain) {
            TimeGrain::Day => "strftime('%Y-%m-%d', {$quotedColumn})",

            // The Monday's date, via SQLite's modifier chain — and the order is
            // load-bearing. `weekday 1` advances to the next Monday but STAYS PUT
            // if the date is already a Monday, so the tempting
            // `'weekday 1', '-7 days'` is correct for six days a week and lands a
            // week early on Mondays. Going back six days first removes the special
            // case: from any day of the week that lands somewhere in the previous
            // week, and the forward hop then always walks to this week's Monday.
            TimeGrain::Week => "strftime('%Y-%m-%d', {$quotedColumn}, '-6 days', 'weekday 1')",

            TimeGrain::Month => "strftime('%Y-%m-01', {$quotedColumn})",

            // No QUARTER() here, so the month is divided. CAST to INTEGER first:
            // strftime returns the text '03', and SQLite's `/` on text operands
            // coerces to REAL, which would make this `1.6666` rather than `1`.
            TimeGrain::Quarter => "strftime('%Y', {$quotedColumn}) || '-Q' || "
                ."((CAST(strftime('%m', {$quotedColumn}) AS INTEGER) + 2) / 3)",

            TimeGrain::Year => "strftime('%Y', {$quotedColumn})",
        };
    }
}
