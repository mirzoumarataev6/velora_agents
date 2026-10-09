<?php

namespace App\Services\Bi\Dashboard;

use App\Services\Bi\Query\QuerySpec;

/**
 * A named stand-in for "whatever the spec layer allows", so WidgetType's shape
 * rules can say "no extra limit of my own" without repeating a config lookup in
 * a `match` arm — where a function call would be evaluated for every case.
 */
final class QueryFieldLimit
{
    /**
     * Deliberately a large constant rather than `QuerySpec::maxFields()`: this
     * is the ABSENCE of a type-specific rule, and QuerySpec applies the real
     * ceiling afterwards either way. Reading config here would make a widget's
     * declared shape depend on a setting that is enforced elsewhere.
     */
    public const MAX = 9999;

    /** The ceiling that actually applies, for anything that needs the number. */
    public static function actual(): int
    {
        return QuerySpec::maxFields();
    }
}
