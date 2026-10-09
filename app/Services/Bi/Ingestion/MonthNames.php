<?php

namespace App\Services\Bi\Ingestion;

/**
 * Written-out month names, folded to numbers.
 *
 * Its own class because two callers must agree exactly: DateFormatVoter uses it
 * to decide that a column IS a date column, and TypeCaster uses it to cast that
 * column's values. If they folded differently, inference would promise a date
 * column whose rows then failed to cast — the single most confusing outcome
 * ingestion can produce, because the type looks right and the data is empty.
 *
 * PHP's own parser handles English month names and nothing else, so `15 марта
 * 2026` and `15 марти 2026` would both be text without this.
 *
 * Matched as PREFIXES because Russian and Tajik decline them — "март", "марта",
 * "марти" are one month, and listing every case would be longer and less
 * forgiving than matching the stem. The prefixes are ordered longest-first within
 * each month so a longer stem cannot be shadowed by a shorter one.
 */
final class MonthNames
{
    /** @var array<int, array<int, string>> */
    private const PREFIXES = [
        1 => ['январ', 'янв', 'janvar', 'january', 'jan'],
        2 => ['феврал', 'фев', 'fevral', 'february', 'feb'],
        3 => ['март', 'мар', 'march', 'mar'],
        4 => ['април', 'апр', 'aprel', 'april', 'apr'],
        5 => ['май', 'мая', 'маи', 'may', 'mai'],
        6 => ['июн', 'june', 'jun', 'iyun'],
        7 => ['июл', 'july', 'jul', 'iyul'],
        8 => ['август', 'авг', 'august', 'aug'],
        9 => ['сентябр', 'сен', 'september', 'sentyabr', 'sep'],
        10 => ['октябр', 'окт', 'october', 'oktyabr', 'oct'],
        11 => ['ноябр', 'ноя', 'november', 'noyabr', 'nov'],
        12 => ['декабр', 'дек', 'december', 'dekabr', 'dec'],
    ];

    /**
     * `15 марта 2026` → `15.03.2026`, leaving anything else untouched.
     *
     * The output is deliberately the dotted day-first spelling, because that is a
     * shape the numeric candidate formats already parse — so adding named months
     * needed no new format, and the day/month evidence scan reads the result
     * correctly too.
     *
     * Day-first only (`15 марта`), not month-first (`марта 15`): the latter is an
     * English convention that does not occur in the files this reaches, and
     * accepting both would make `03 05 2026` style input ambiguous again.
     */
    public static function fold(string $value): string
    {
        if (preg_match('/^(\d{1,2})[\s.]+(\p{L}{3,})[\s.,]+(\d{4})(.*)$/u', $value, $m) !== 1) {
            return $value;
        }

        $number = self::numberFor($m[2]);

        if ($number === null) {
            return $value;
        }

        // The remainder is kept so `15 марта 2026 14:30` still carries its time
        // into `15.03.2026 14:30`, which `d.m.Y H:i` then parses.
        return sprintf('%02d.%02d.%04d', (int) $m[1], $number, (int) $m[3]).$m[4];
    }

    /** The month number a written-out name refers to, or null. */
    public static function numberFor(string $name): ?int
    {
        $folded = mb_strtolower(trim($name));

        foreach (self::PREFIXES as $number => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($folded, $prefix)) {
                    return $number;
                }
            }
        }

        return null;
    }
}
