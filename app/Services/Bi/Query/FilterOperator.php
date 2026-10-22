<?php

namespace App\Services\Bi\Query;

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

    public function arity(): ?int
    {
        return match ($this) {
            self::IsBlank, self::IsNotBlank => 0,
            self::In, self::NotIn => null,
            self::Between => 2,
            default => 1,
        };
    }

    public function isPattern(): bool
    {
        return $this === self::Contains
            || $this === self::StartsWith
            || $this === self::EndsWith;
    }

    public function needsOrdering(): bool
    {
        return match ($this) {
            self::GreaterThan, self::GreaterOrEqual,
            self::LessThan, self::LessOrEqual, self::Between => true,
            default => false,
        };
    }

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

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
