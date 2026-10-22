<?php

namespace App\Services\Bi\Query;

enum TimeGrain: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

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

    public function isDateShaped(): bool
    {
        return $this !== self::Quarter;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
