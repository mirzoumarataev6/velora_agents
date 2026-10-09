<?php

namespace App\Services\Bi\Schema;

/**
 * What a column is FOR, as opposed to what it holds.
 *
 * The type says a column is a decimal; the role says whether the right thing to
 * do with it is add it up (measure), split by it (dimension), or put it on an
 * axis (time). This is the distinction every BI tool makes, and it is the
 * distinction that lets the AI analyst propose something sensible from a schema
 * it has never seen: "средняя выручка по регионам" is a measure and a dimension,
 * and which column is which is not guessable from `decimal` alone.
 *
 * Inferred, then overridable. The two cases inference reliably gets wrong are a
 * year stored as an integer (a dimension that looks like a measure) and an id
 * column (likewise) — so the owner can always say otherwise.
 */
enum ColumnRole: string
{
    case Dimension = 'dimension';
    case Measure = 'measure';
    case Time = 'time';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
