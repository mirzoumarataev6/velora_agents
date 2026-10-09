<?php

namespace App\Services\Bi\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * The closed set of types a materialized BI column can have.
 *
 * Deliberately small. Every extra type is another branch in the caster, another
 * DDL shape, another set of aggregate rules, and another way for two engines to
 * disagree — and none of the ones missing here (time, interval, JSON, geometry)
 * is something an owner uploading a sales export needs to group or sum.
 *
 * An enum rather than strings because this is the vocabulary the whole module
 * agrees on: inference produces one of these, the schema editor offers exactly
 * these, the DDL is generated from them, and the query compiler decides what an
 * aggregate means by asking one. A typo'd string would be a runtime surprise; a
 * typo'd case is a parse error.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';

    /**
     * The order inference tries types in: most specific first.
     *
     * Date before the numbers because "2026" parses as an integer and a year is
     * not a quantity; integer before decimal because a column of whole numbers
     * is not improved by six decimal places; boolean last of the specifics
     * because it only ever claims a column whose distinct values are words.
     *
     * @return array<int, self>
     */
    public static function inferenceOrder(): array
    {
        return [self::DateTime, self::Date, self::Integer, self::Decimal, self::Boolean];
    }

    /**
     * Add this column to a runtime CREATE TABLE.
     *
     * Every column is nullable, without exception. A blank cell is a fact about
     * the owner's file, and a NOT NULL column would turn it into a failed
     * import — so the absence is stored as an absence, and the count of them is
     * reported on `bi_columns.blank_count`.
     *
     * $long is only consulted for text: a column whose longest value exceeds the
     * indexable length is materialized as TEXT and never indexed, because
     * truncating the tail of a description to make it groupable trades data for
     * a GROUP BY nobody asked for.
     */
    public function define(Blueprint $table, string $name, bool $long = false): void
    {
        match ($this) {
            // 20 digits with 6 after the point. Wide enough for money in any
            // currency this product sees and for the ratios an average produces,
            // and fixed-point rather than float because a dashboard that reports
            // 0.30000000000000004 has lost the owner's trust over nothing.
            self::Decimal => $table->decimal($name, 20, 6)->nullable(),
            self::Integer => $table->bigInteger($name)->nullable(),
            self::Date => $table->date($name)->nullable(),
            self::DateTime => $table->dateTime($name)->nullable(),
            self::Boolean => $table->boolean($name)->nullable(),
            self::Text => $long
                ? $table->text($name)->nullable()
                : $table->string($name, self::indexableLength())->nullable(),
        };
    }

    /** Longest text value that still lives in an indexable VARCHAR column. */
    public static function indexableLength(): int
    {
        return max(1, (int) config('agent.bi.ingest.indexable_text_length', 512));
    }

    /** Whether SUM and AVG are meaningful on this column. */
    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Decimal;
    }

    /** Whether this column can be bucketed by day, month, quarter or year. */
    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }

    /**
     * The role a freshly inferred column of this type starts with.
     *
     * A guess, and an overridable one — but a carefully narrow guess, because the
     * obvious version of it is wrong. Demoting any LOW-CARDINALITY numeric column
     * to a dimension seems reasonable and quietly breaks the main use case: a
     * three-row sample of "Выручка" has three distinct values, and a revenue column
     * that arrives as a dimension cannot be summed, which is the one thing anybody
     * wants to do with it.
     *
     * So a number is a measure, full stop, with exactly one exception:
     * `$looksLikeYear`, for an integer column whose values are all plausible years.
     * That case is common enough to be worth catching (`2024, 2025, 2026` is a
     * dimension in every way that matters, and summing it is meaningless) and
     * specific enough that it cannot swallow a quantity or a price.
     */
    public function defaultRole(bool $looksLikeYear = false): ColumnRole
    {
        return match (true) {
            $this->isTemporal() => ColumnRole::Time,
            $this->isNumeric() && ! $looksLikeYear => ColumnRole::Measure,
            default => ColumnRole::Dimension,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
