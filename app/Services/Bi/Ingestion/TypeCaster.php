<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\Schema\ColumnType;
use DateTimeInterface;

class TypeCaster
{
    private const BLANKS = [
        '', '-', '--', '—', '–', 'n/a', 'n\\a', 'na', 'null', 'nil', 'none', '#n/a',
        '#null!', '#div/0!', '#value!', '#ref!', 'нет данных', 'не указано', 'отсутствует',

        'н/д', 'нд', 'нест', 'маълумот нест',
    ];

    private const TRUE_WORDS = ['true', 'yes', 'y', 'да', 'истина', 'есть', 'ҳа', 'ха', 'бале'];

    private const FALSE_WORDS = ['false', 'no', 'n', 'нет', 'ложь', 'net', 'не'];

    private const NUMBER_SPACES = [' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}", "'", '`'];

    private const ISO_FORMATS = [
        'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
    ];

    private const CURRENCY = ['₽', '$', '€', '£', '¥', '₸', '₴', 'c.', 'с.', 'сом', 'сомони', 'руб', 'руб.', 'р.', 'usd', 'eur', 'rub', 'tjs'];

    public function cast(mixed $raw, ColumnType $type, array $format = []): CastResult
    {

        if ($raw instanceof DateTimeInterface) {
            return match ($type) {
                ColumnType::Date => CastResult::ok($raw->format('Y-m-d')),
                ColumnType::DateTime => CastResult::ok($raw->format('Y-m-d H:i:s')),
                ColumnType::Text => CastResult::ok($raw->format('Y-m-d H:i:s')),

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

            ColumnType::Integer => CastResult::ok((int) $raw),
            ColumnType::Text => CastResult::ok($this->stringifyNumber($raw)),
            ColumnType::Boolean => CastResult::failed(),

            ColumnType::Date, ColumnType::DateTime => CastResult::failed(),
        };
    }

    private function castTextInteger(string $value): CastResult
    {
        $number = $this->parseNumber($value);

        if ($number === null) {
            return CastResult::failed();
        }

        if (abs($number - round($number)) > 0.0000001) {
            return CastResult::failed();
        }

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

        return match ($folded) {
            '1' => CastResult::ok(1),
            '0' => CastResult::ok(0),
            default => CastResult::failed(),
        };
    }

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

    private function parseNumber(string $value): ?float
    {
        $value = str_replace(self::NUMBER_SPACES, '', $value);

        $negative = false;
        if (preg_match('/^\((.*)\)$/u', $value, $m) === 1) {
            $negative = true;
            $value = $m[1];
        }

        $value = $this->stripCurrency($value);

        $value = str_replace(['−', '–', '—', '＋', '﹣'], ['-', '-', '-', '+', '-'], $value);

        if ($value === '' || preg_match('/^[+-]?[\d.,]+$/', $value) !== 1) {
            return null;
        }

        if (str_starts_with($value, '+')) {
            return null;
        }

        if (str_starts_with($value, '-')) {
            $negative = ! $negative;
            $value = substr($value, 1);
        }

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

    public function clean(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", "\u{FEFF}"], [' ', ' ', ''], $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public function isBlank(string $value): bool
    {
        return in_array(mb_strtolower($value), self::BLANKS, true);
    }
}
