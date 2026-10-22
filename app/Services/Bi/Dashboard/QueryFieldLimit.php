<?php

namespace App\Services\Bi\Dashboard;

use App\Services\Bi\Query\QuerySpec;

final class QueryFieldLimit
{
    public const MAX = 9999;

    public static function actual(): int
    {
        return QuerySpec::maxFields();
    }
}
