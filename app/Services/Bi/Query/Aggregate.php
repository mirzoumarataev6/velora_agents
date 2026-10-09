<?php

namespace App\Services\Bi\Query;

use App\Services\Bi\Schema\ColumnType;

/**
 * The closed set of aggregates a measure can ask for.
 *
 * Six, and no more without a reason. Every addition is another branch in two
 * compilers that must agree, and the ones missing here — median, percentile,
 * stddev, mode — are either absent from SQLite entirely or spelled differently
 * enough in MySQL that "the same query, two engines" would stop being true. That
 * equivalence is what lets the suite test the real compiler against a real
 * database, so it is worth more than a percentile.
 *
 * An enum rather than a validated string for the same reason ColumnType is one:
 * this is the vocabulary the spec, the validator, both compilers and the UI all
 * agree on, and a typo should be a parse error rather than a runtime surprise.
 */
enum Aggregate: string
{
    case Count = 'count';
    case CountDistinct = 'count_distinct';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';

    /**
     * Whether this aggregate needs a column at all.
     *
     * Only COUNT does not: it counts rows, and `COUNT(*)` is a different question
     * from `COUNT(c3)` — the latter skips nulls, and in this module nulls are
     * ordinary (every materialized column is nullable, because a blank cell is a
     * fact about the owner's file rather than an import failure). Conflating the
     * two would quietly under-report row counts on any dataset with a gap in it.
     */
    public function needsColumn(): bool
    {
        return $this !== self::Count;
    }

    /**
     * Whether this aggregate is only meaningful on a number.
     *
     * MIN and MAX are deliberately NOT in this list: the earliest order date and
     * the alphabetically first region are both useful answers, and both engines
     * compute them correctly on dates and text.
     */
    public function needsNumeric(): bool
    {
        return $this === self::Sum || $this === self::Avg;
    }

    /** Whether this aggregate may be applied to a column of the given type. */
    public function accepts(ColumnType $type): bool
    {
        return ! $this->needsNumeric() || $type->isNumeric();
    }

    /**
     * The type the RESULT has, which is not always the input's.
     *
     * AVG of two integers is not an integer, and COUNT of anything is. The UI
     * formats on this rather than on the source column's type, so an average
     * order size renders as 3.5 instead of being truncated to 3.
     */
    public function resultType(?ColumnType $input): ColumnType
    {
        return match ($this) {
            self::Count, self::CountDistinct => ColumnType::Integer,
            self::Avg => ColumnType::Decimal,
            self::Sum => $input === ColumnType::Integer ? ColumnType::Integer : ColumnType::Decimal,
            self::Min, self::Max => $input ?? ColumnType::Text,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
