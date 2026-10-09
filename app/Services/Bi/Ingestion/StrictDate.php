<?php

namespace App\Services\Bi\Ingestion;

use DateTimeImmutable;

/**
 * One strict date parse, shared by the voter and the caster.
 *
 * Both need exactly the same answer to "is this value exactly this format?" — the
 * voter to decide a column IS a date column, the caster to fill it. Two
 * implementations would eventually disagree, and the symptom would be a column
 * confidently typed as a date whose rows then all failed to cast.
 *
 * `createFromFormat` alone is not enough, for three separate reasons:
 *
 *  1. IT IS LENIENT. `31.02.2026` parses to the 3rd of March with a warning rather
 *     than a failure. A column of malformed dates would be "recognised" and then
 *     silently shifted, so the warning and error counts are checked too.
 *  2. `Y` ACCEPTS ONE TO FOUR DIGITS. `17.03.26` parses under `d.m.Y` as the year
 *     26 AD — cleanly, with no warning. Without a plausibility check the two-digit
 *     year column below would be materialized a full two millennia early, which is
 *     exactly the bug the suite caught.
 *  3. UNSPECIFIED FIELDS INHERIT NOW. A date-only format would pick up today's
 *     clock, so the same file would import to different values depending on when it
 *     was imported. The leading `!` resets them.
 */
final class StrictDate
{
    /**
     * Years a business data file can plausibly contain.
     *
     * Generous on both sides — a founding date in 1950 and a contract running to
     * 2100 are both legitimate — and narrow enough to catch a two-digit year
     * misread as an absolute one, which is the case this exists for.
     */
    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2200;

    public static function parse(string $value, string $format): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);

        if ($parsed === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        $year = (int) $parsed->format('Y');

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return $parsed;
    }

    public static function matches(string $value, string $format): bool
    {
        return self::parse($value, $format) !== null;
    }
}
