<?php

namespace App\Services\Bi\Query;

/**
 * How a date column is bucketed on an axis.
 *
 * THE INVARIANT THIS ENUM EXISTS TO PROTECT: for a given grain, MySQL and SQLite
 * must produce the BYTE-IDENTICAL bucket string for the same input date. Not
 * merely equivalent — identical, because the bucket is what the chart groups on,
 * what the axis label is rendered from, and what a cached result is keyed by. A
 * dashboard that said `2026-W05` in production and `2026-W04` in the test suite
 * would have no test worth the name.
 *
 * That constraint, not expressiveness, is what shaped the set:
 *
 *  - WEEK is the Monday's DATE rather than a week number. MySQL's `%v` is an ISO
 *    week and SQLite's `%W` is not — they disagree every January — and chasing
 *    that with CASE arithmetic in two dialects is how you get an off-by-one that
 *    only appears in week 1. A Monday is a date both engines can name exactly,
 *    and it sorts correctly as a string for free, which a `2026-W05` does not
 *    across a year boundary.
 *  - QUARTER is composed (`2026-Q1`) because neither engine formats one, so both
 *    do the same integer arithmetic on the month.
 *  - Hour and minute are absent on purpose. This module ingests business
 *    exports, where the time half of a timestamp is usually an artifact of the
 *    export rather than data, and bucketing by it invites a chart with 8,760
 *    points that means nothing.
 *
 * `format()` below is the contract both compilers are tested against.
 */
enum TimeGrain: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    /**
     * The exact shape of the bucket string, for the one test that matters.
     *
     * Month is the first of the month rather than `2026-03` so that every grain
     * except quarter is a real, parseable date — the UI formats an axis label by
     * parsing this, and one grain that needs a special case is cheaper than four.
     */
    public function example(): string
    {
        return match ($this) {
            self::Day => '2026-03-14',
            self::Week => '2026-03-09',
            self::Month => '2026-03-01',
            self::Quarter => '2026-Q1',
            self::Year => '2026',
        };
    }

    /** Whether the bucket is a date string the UI can parse, rather than a label. */
    public function isDateShaped(): bool
    {
        return $this !== self::Quarter;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
