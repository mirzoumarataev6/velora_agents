<?php

namespace App\Services\Bi\Dashboard;

enum WidgetType: string
{
    public const SERIES_SLOTS = 6;

    case Line = 'line';
    case Area = 'area';
    case Bar = 'bar';
    case Column = 'column';
    case Donut = 'donut';
    case Table = 'table';
    case Kpi = 'kpi';

    public function dimensionRange(): array
    {
        return match ($this) {

            self::Kpi => [0, 0],

            self::Line, self::Area, self::Donut => [1, 1],

            self::Bar, self::Column => [1, 2],

            self::Table => [0, QueryFieldLimit::MAX],
        };
    }

    public function measureRange(): array
    {
        return match ($this) {
            self::Kpi => [1, 1],

            self::Donut => [1, 1],

            self::Line, self::Area, self::Bar, self::Column => [1, 4],
            self::Table => [0, QueryFieldLimit::MAX],
        };
    }

    public function ranksByDefault(): bool
    {
        return $this === self::Bar || $this === self::Column || $this === self::Donut;
    }

    public function isSequential(): bool
    {
        return $this === self::Line || $this === self::Area;
    }

    public function defaultLimit(): int
    {
        return match ($this) {
            self::Kpi => 1,

            self::Donut => self::SERIES_SLOTS + 1,
            self::Bar, self::Column => 20,
            self::Line, self::Area => 200,
            self::Table => 50,
        };
    }

    public function hasLegend(): bool
    {
        return $this !== self::Kpi && $this !== self::Table;
    }

    public function component(): string
    {
        return 'bi.widget-'.$this->value;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
