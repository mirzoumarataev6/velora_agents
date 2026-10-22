<?php

namespace App\Services\Bi\Query;

use App\Services\Bi\Schema\ColumnType;

enum Aggregate: string
{
    case Count = 'count';
    case CountDistinct = 'count_distinct';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';

    public function needsColumn(): bool
    {
        return $this !== self::Count;
    }

    public function needsNumeric(): bool
    {
        return $this === self::Sum || $this === self::Avg;
    }

    public function accepts(ColumnType $type): bool
    {
        return ! $this->needsNumeric() || $type->isNumeric();
    }

    public function resultType(?ColumnType $input): ColumnType
    {
        return match ($this) {
            self::Count, self::CountDistinct => ColumnType::Integer,
            self::Avg => ColumnType::Decimal,
            self::Sum => $input === ColumnType::Integer ? ColumnType::Integer : ColumnType::Decimal,
            self::Min, self::Max => $input ?? ColumnType::Text,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
