<?php

namespace App\Services\Bi\Query;

/**
 * The closed set of comparisons a filter can express.
 *
 * Wider than the live-database layer's five (`ProductQuery::OPERATORS`), and the
 * difference is the use case rather than the risk: that one serves a sales bot
 * narrowing a product list, this one serves an owner slicing their own data, and
 * "region is one of these three" is the first thing anybody asks of a sales
 * export. The safety property is unchanged — the operator is chosen from THIS
 * list by identity, never spliced from a request string, and every value it
 * takes is bound.
 *
 * Deliberately absent: regex and raw LIKE. Both let the caller supply pattern
 * syntax rather than a value, which turns a bound parameter back into a small
 * language — and a catastrophic-backtracking regex on two million rows is a
 * denial of service the timeout only half-contains. `Contains` below escapes its
 * wildcards for exactly this reason.
 */
enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'ne';
    case GreaterThan = 'gt';
    case GreaterOrEqual = 'gte';
    case LessThan = 'lt';
    case LessOrEqual = 'lte';
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Between = 'between';
    case IsBlank = 'is_blank';
    case IsNotBlank = 'is_not_blank';

    /**
     * How many values this operator needs.
     *
     * `null` means "one or more" — the set operators, whose whole point is a list.
     * Checked by the validator rather than assumed by the compiler, so a filter
     * with two values for `between` is a classified refusal instead of an SQL
     * syntax error from the server.
     */
    public function arity(): ?int
    {
        return match ($this) {
            self::IsBlank, self::IsNotBlank => 0,
            self::In, self::NotIn => null,
            self::Between => 2,
            default => 1,
        };
    }

    /** Whether this operator compares text patterns rather than whole values. */
    public function isPattern(): bool
    {
        return $this === self::Contains
            || $this === self::StartsWith
            || $this === self::EndsWith;
    }

    /**
     * Whether this operator only makes sense on an ordered type.
     *
     * Used by the validator to refuse `gt` on a boolean, which is the kind of
     * filter a UI bug produces and a server silently answers with nonsense.
     */
    public function needsOrdering(): bool
    {
        return match ($this) {
            self::GreaterThan, self::GreaterOrEqual,
            self::LessThan, self::LessOrEqual, self::Between => true,
            default => false,
        };
    }

    /**
     * The LIKE pattern for a pattern operator, with the caller's wildcards
     * neutralized.
     *
     * A customer searching for "50%" means fifty percent, not "anything starting
     * with 50" — so `%` and `_` in the VALUE are escaped, and only the wildcards
     * this method adds itself are live. Backslash is escaped first, or escaping
     * the others would double-escape it.
     *
     * The escape character is stated explicitly in the compiled SQL (`ESCAPE
     * '\'`) rather than left to the engine: MySQL defaults to backslash and
     * SQLite defaults to NO escape character at all, so without it the same
     * filter would behave differently on the two engines the suite compares.
     */
    public function pattern(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return match ($this) {
            self::Contains => '%'.$escaped.'%',
            self::StartsWith => $escaped.'%',
            self::EndsWith => '%'.$escaped,
            default => $escaped,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
