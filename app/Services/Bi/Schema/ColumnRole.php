<?php

namespace App\Services\Bi\Schema;

enum ColumnRole: string
{
    case Dimension = 'dimension';
    case Measure = 'measure';
    case Time = 'time';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
