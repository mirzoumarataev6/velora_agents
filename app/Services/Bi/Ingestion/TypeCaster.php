<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\Schema\ColumnType;
use DateTimeInterface;

/**
 * Turns one raw cell into one typed value — or says it cannot.
 *
 * Used twice with the same rules, which is the point of it being its own class:
 * inference asks "would this cast?" to decide a column's type, and
 * materialization asks "cast this" to fill the table. If those two used different
 * logic, a column would be declared `decimal` and then reject 8% of its values.
 *
 * `null` is returned for a blank, and blank is a real answer — not a failure.
 * `CastResult::failed()` is reserved for a value that is present and genuinely
 * does not fit, which is what gets counted onto `bi_columns.cast_failures` and
 * shown to the owner.
 */
class TypeCaster
{
    /**
     * Strings that mean "no value" across the three locales this product serves,
     * plus the placeholders spreadsheets and exports leave behind.
     *
     * These become NULL rather than cast failures: a cell saying "не указано" is
     * an empty cell that somebody typed into, and counting thousands of them as
     * rejects would bury the handful of real ones.
     */
    private const BLANKS = [
        '', '-', '--', '—', '–', 'n/a', 'n\\a', 'na', 'null', 'nil', 'none', '#n/a',
        '#null!', '#div/0!', '#value!', '#ref!', 'нет данных', 'не указано', 'отсутствует',
        // 'н/д' and 'нд' are how Russian-language exports abbreviate "нет данных",
        // and they are extremely common. Without them, a column that is 6% "н/д"
        // would be reported as having 6% rejected values — technically true and
        // completely unhelpful.
        'н/д', 'нд', 'нест', 'маълумот нест',
    ];

    /**
     * Words that make a column boolean.
     *
     * DELIBERATELY EXCLUDES "0" AND "1". A column of zeroes and ones is far more
     * often a count, a quantity or an id than a flag, and turning it into a
     * boolean would make SUM over it meaningless — which is exactly what an owner
     * would then try. A column of words, on the other hand, can be nothing else.
     * An owner whose 0/1 column really is a flag can say so in the schema editor.
     */
    private const TRUE_WORDS = ['true', 'yes', 'y', 'да', 'истина', 'есть', 'ҳа', 'ха', 'бале'];

    /**
     * 'нест' is deliberately absent: it is already a recognized BLANK, because in
     * Tajik it carries both "no" and "there is none". Where a word could mean
     * either "false" or "unknown", storing it as unknown is the honest reading —
     * asserting a "no" the owner may not have meant is the worse mistake.
     */
    private const FALSE_WORDS = ['false', 'no', 'n', 'нет', 'ложь', 'net', 'не'];

    /** Space-like characters that appear inside numbers and must not defeat them. */
    private const NUMBER_SPACES = [' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}", "'", '`'];

    /**
     * ISO-8601 spellings accepted without a stored pattern, LONGEST FIRST.
     *
     * Order is the whole correctness of this list: `Y-m-d` would match the first
     * ten characters of `2026-03-01T14:30:00` and silently throw the clock away,
     * so the time-bearing spellings have to be tried before it. This is also the
     * path every spreadsheet date takes — the normalizer serializes a real date
     * cell to ISO — which is why losing the time here would lose it for every
     * .xlsx import.
     */
    private const ISO_FORMATS = [
        'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
    ];

    /** Currency marks allowed to top and tail a number. */
    private const CURRENCY = ['₽', '$', '€', '£', '¥', '₸', '₴', 'c.', 'с.', 'сом', 'сомони', 'руб', 'руб.', 'р.', 'usd', 'eur', 'rub', 'tjs'];

    /**
     * Cast a raw reader value to a type.
     *
     * @param  array<string, mixed>  $format  Hints from inference — notably
     *                                        `date_format` for a text date column.
     */
    public function cast(mixed $raw, ColumnType $type, array $format = []): CastResult
    {
        // A date-formatted Excel cell arrives already parsed, which is why the
        // readers are careful to preserve it. Handled before blank-checking
        // because a DateTimeInterface is never blank and stringifying it first
        // would throw away the precision we were given.
        if ($raw instanceof DateTimeInterface) {
            return match ($type) {
                ColumnType::Date => CastResult::ok($raw->format('Y-m-d')),
                ColumnType::DateTime => CastResult::ok($raw->format('Y-m-d H:i:s')),
                ColumnType::Text => CastResult::ok($raw->format('Y-m-d H:i:s')),
                // A date in a column the owner re-typed as a number is a real
                // rejection, not a silent zero.
                default => CastResult::failed(),
            };
        }

        if ($raw === null) {
            return CastResult::blank();
        }

        if (is_bool($raw)) {
            return match ($type) {
                ColumnType::Boolean => CastResult::ok($raw ? 1 : 0),
                ColumnType::Integer, ColumnType::Decimal => CastResult::ok($raw ? 1 : 0),
                ColumnType::Text => CastResult::ok($raw ? 'true' : 'false'),
                default => CastResult::failed(),
            };
        }

        // Numbers from a spreadsheet arrive as int or float and need no parsing —
        // only range and shape checks.
        if (is_int($raw) || is_float($raw)) {
            return $this->castNumber($raw, $type);
        }

        $value = $this->clean((string) $raw);

        if ($this->isBlank($value)) {
            return CastResult::blank();
        }

        return match ($type) {
            ColumnType::Text => CastResult::ok($value),
            ColumnType::Integer => $this->castTextInteger($value),
            ColumnType::Decimal => $this->castTextDecimal($value),
            ColumnType::Boolean => $this->castTextBoolean($value),
            ColumnType::Date, ColumnType::DateTime => $this->castTextDate($value, $type, $format),
        };
    }

    private function castNumber(int|float $raw, ColumnType $type): CastResult
    {
        if (is_float($raw) && (is_nan($raw) || is_infinite($raw))) {
            return CastResult::failed();
        }

        return match ($type) {
            ColumnType::Decimal => CastResult::ok(number_format((float) $raw, 6, '.', '')),
            // A float in an integer column is truncated rather than rejected:
            // 3.0 is an integer that a spreadsheet stored as a float, which is
            // the overwhelmingly common case. A genuinely fractional value in a
            // column inference called integer cannot happen — inference would
            // have chosen decimal — so this only fires after an owner re-typed
            // the column by hand, where losing the fraction is what they asked
            // for.
            ColumnType::Integer => CastResult::ok((int) $raw),
            ColumnType::Text => CastResult::ok($this->stringifyNumber($raw)),
            ColumnType::Boolean => CastResult::failed(),
            // An Excel serial in a column typed as a date, where the cell's
            // format did not say so. Refused rather than guessed: 45000 as a
            // date is a decision only the owner can make.
            ColumnType::Date, ColumnType::DateTime => CastResult::failed(),
        };
    }

    private function castTextInteger(string $value): CastResult
    {
        $number = $this->parseNumber($value);

        if ($number === null) {
            return CastResult::failed();
        }

        // A value with a real fraction is not an integer. Rejected and counted,
        // rather than truncated — a column where 4% of rows lose their decimals
        // is a dashboard that quietly under-reports.
        if (abs($number - round($number)) > 0.0000001) {
            return CastResult::failed();
        }

        // Outside BIGINT. A 25-digit "number" is an account or a barcode that
        // should have been text, and storing it would silently change it.
        if ($number > 9.2233720368547e18 || $number < -9.2233720368547e18) {
            return CastResult::failed();
        }

        return CastResult::ok((int) round($number));
    }

    private function castTextDecimal(string $value): CastResult
    {
        $number = $this->parseNumber($value);

        if ($number === null) {
            return CastResult::failed();
        }

        // DECIMAL(20,6) holds 14 digits before the point.
        if (abs($number) >= 1.0e14) {
            return CastResult::failed();
        }

        return CastResult::ok(number_format($number, 6, '.', ''));
    }

    private function castTextBoolean(string $value): CastResult
    {
        $folded = mb_strtolower($value);

        if (in_array($folded, self::TRUE_WORDS, true)) {
            return CastResult::ok(1);
        }

        if (in_array($folded, self::FALSE_WORDS, true)) {
            return CastResult::ok(0);
        }

        // 0/1 are accepted on the way IN even though they never make a column
        // boolean on their own (see TRUE_WORDS): once an owner has said the
        // column is a flag, its zeroes and ones are what they meant.
        return match ($folded) {
            '1' => CastResult::ok(1),
            '0' => CastResult::ok(0),
            default => CastResult::failed(),
        };
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function castTextDate(string $value, ColumnType $type, array $format): CastResult
    {
        $pattern = is_string($format['date_format'] ?? null) ? $format['date_format'] : null;

        if ($pattern !== null) {
            $parsed = StrictDate::parse(MonthNames::fold($value), $pattern);

            if ($parsed !== null) {
                return CastResult::ok($parsed->format(
                    $type === ColumnType::Date ? 'Y-m-d' : 'Y-m-d H:i:s'
                ));
            }
        }

        // No stored pattern, or a row that does not match it. ISO is tried as a
        // last resort because it is unambiguous, extremely common as the odd row
        // out, and the shape a spreadsheet's own date cells are written in when
        // the normalizer serializes them to a shard. Anything else is a counted
        // failure rather than a guess: guessing per row is how a column ends up
        // with March and April dates interleaved out of the same text.
        foreach (self::ISO_FORMATS as $iso) {
            $parsed = StrictDate::parse($value, $iso);

            if ($parsed !== null) {
                return CastResult::ok($parsed->format(
                    $type === ColumnType::Date ? 'Y-m-d' : 'Y-m-d H:i:s'
                ));
            }
        }

        return CastResult::failed();
    }

    /**
     * A number out of text written the way people and spreadsheets write them.
     *
     * The hard part is that `1,500` is one thousand five hundred in an English
     * export and one and a half in a German one, and the file does not say which.
     * The rule used here, and the reasoning:
     *
     *  - BOTH separators present → the LAST one is the decimal point. This is
     *    unambiguous and always correct: `1.500,25` and `1,500.25` both resolve.
     *  - ONE separator, followed by exactly three digits → a thousands separator.
     *    `1,500` becomes 1500. This is the reading that is right far more often,
     *    and it is the one Excel itself assumes.
     *  - ONE separator, followed by one, two, or four-plus digits → a decimal
     *    point. `1,5` and `0.25` and `3,1416`.
     */
    private function parseNumber(string $value): ?float
    {
        $value = str_replace(self::NUMBER_SPACES, '', $value);

        // Accounting parentheses: (1500) is −1500 in every финансовый export.
        $negative = false;
        if (preg_match('/^\((.*)\)$/u', $value, $m) === 1) {
            $negative = true;
            $value = $m[1];
        }

        $value = $this->stripCurrency($value);

        // Unicode minus and the full-width variants people paste from PDFs.
        $value = str_replace(['−', '–', '—', '＋', '﹣'], ['-', '-', '-', '+', '-'], $value);

        if ($value === '' || preg_match('/^[+-]?[\d.,]+$/', $value) !== 1) {
            return null;
        }

        // A LEADING PLUS IS REFUSED, which is a deliberate and slightly unusual
        // choice. Nothing in a spreadsheet export writes a positive quantity as
        // "+1500" — Excel never does — whereas "+992 90 011 22 33" is a phone
        // number, and stripping its spaces would turn a whole column of them into
        // integers with the "+" silently gone. Between a signed positive nobody
        // writes and a phone column everybody has, the phone column wins.
        if (str_starts_with($value, '+')) {
            return null;
        }

        if (str_starts_with($value, '-')) {
            $negative = ! $negative;
            $value = substr($value, 1);
        }

        // A LEADING ZERO means an identifier, not a quantity: an account number, a
        // barcode, a phone number written without its country code. Parsing it
        // would drop the zero and change the value, so the column stays text.
        // `0.5` and `0,5` are the obvious exceptions and are still numbers.
        if (preg_match('/^0\d/', $value) === 1) {
            return null;
        }

        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $decimalAt = max($lastDot, $lastComma);
            $integer = str_replace([',', '.'], '', substr($value, 0, $decimalAt));
            $fraction = substr($value, $decimalAt + 1);
        } elseif ($lastDot !== false || $lastComma !== false) {
            $decimalAt = $lastDot !== false ? $lastDot : $lastComma;
            $separator = $value[$decimalAt];
            $fractionLength = strlen($value) - $decimalAt - 1;
            $occurrences = substr_count($value, $separator);

            // Repeated separators can only be grouping: `1.234.567`. But the
            // SHAPE has to be checked, not just the count — `1..2` has two dots
            // and is not a number, and without this it would have been read as 12.
            if ($occurrences > 1) {
                if (! $this->isGrouped($value, $separator)) {
                    return null;
                }

                $integer = str_replace([',', '.'], '', $value);
                $fraction = '';
            } elseif ($fractionLength === 3) {
                $integer = str_replace([',', '.'], '', $value);
                $fraction = '';
            } else {
                $integer = substr($value, 0, $decimalAt);
                $fraction = substr($value, $decimalAt + 1);
            }
        } else {
            $integer = $value;
            $fraction = '';
        }

        // A grouped integer part with any non-digit left in it, or an empty one,
        // was never a number: `1..2`, `.`, `,5,`.
        if ($integer !== '' && preg_match('/^\d+$/', $integer) !== 1) {
            return null;
        }

        if ($fraction !== '' && preg_match('/^\d+$/', $fraction) !== 1) {
            return null;
        }

        if ($integer === '' && $fraction === '') {
            return null;
        }

        $number = (float) (($integer === '' ? '0' : $integer).'.'.($fraction === '' ? '0' : $fraction));

        return $negative ? -$number : $number;
    }

    /**
     * Whether a value is digits separated into groups of three — `1.234.567`,
     * `12,345,678` — as opposed to merely containing that separator twice.
     */
    private function isGrouped(string $value, string $separator): bool
    {
        $escaped = preg_quote($separator, '/');

        return preg_match('/^\d{1,3}(?:'.$escaped.'\d{3})+$/', $value) === 1;
    }

    private function stripCurrency(string $value): string
    {
        $folded = mb_strtolower($value);

        foreach (self::CURRENCY as $mark) {
            if (str_starts_with($folded, $mark)) {
                $value = mb_substr($value, mb_strlen($mark));
                $folded = mb_strtolower($value);
            }
        }

        foreach (self::CURRENCY as $mark) {
            if (str_ends_with($folded, $mark)) {
                $value = mb_substr($value, 0, mb_strlen($value) - mb_strlen($mark));
                $folded = mb_strtolower($value);
            }
        }

        return trim($value);
    }

    /**
     * A float rendered without exponent notation or a trailing `.0`, so a number
     * stored into a text column reads the way the owner typed it rather than as
     * `1.0E+15`.
     */
    private function stringifyNumber(int|float $raw): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if ($raw === floor($raw) && abs($raw) < 1.0e15) {
            return number_format($raw, 0, '.', '');
        }

        return rtrim(rtrim(number_format($raw, 10, '.', ''), '0'), '.');
    }

    /** Normalize whitespace and strip the spaces that break number parsing. */
    public function clean(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", "\u{FEFF}"], [' ', ' ', ''], $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Whether this already-cleaned value counts as no value at all. */
    public function isBlank(string $value): bool
    {
        return in_array(mb_strtolower($value), self::BLANKS, true);
    }
}
