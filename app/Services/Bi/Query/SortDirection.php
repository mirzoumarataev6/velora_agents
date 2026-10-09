<?php

namespace App\Services\Bi\Query;

enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function sql(): string
    {
        return $this === self::Asc ? 'ASC' : 'DESC';
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
