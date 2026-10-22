<?php

namespace App\Services\Bi\Schema;

use Illuminate\Database\Schema\Blueprint;

enum ColumnType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';

    public static function inferenceOrder(): array
    {
        return [self::DateTime, self::Date, self::Integer, self::Decimal, self::Boolean];
    }

    public function define(Blueprint $table, string $name, bool $long = false): void
    {
        match ($this) {

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

    public static function indexableLength(): int
    {
        return max(1, (int) config('agent.bi.ingest.indexable_text_length', 512));
    }

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Decimal;
    }

    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }

    public function defaultRole(bool $looksLikeYear = false): ColumnRole
    {
        return match (true) {
            $this->isTemporal() => ColumnRole::Time,
            $this->isNumeric() && ! $looksLikeYear => ColumnRole::Measure,
            default => ColumnRole::Dimension,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
