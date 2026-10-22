<?php

namespace App\Services\Bi\Ingestion;

final class MonthNames
{
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

    public static function fold(string $value): string
    {
        if (preg_match('/^(\d{1,2})[\s.]+(\p{L}{3,})[\s.,]+(\d{4})(.*)$/u', $value, $m) !== 1) {
            return $value;
        }

        $number = self::numberFor($m[2]);

        if ($number === null) {
            return $value;
        }

        return sprintf('%02d.%02d.%04d', (int) $m[1], $number, (int) $m[3]).$m[4];
    }

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
