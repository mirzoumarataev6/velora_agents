<?php

namespace App\Services\Bi\Ingestion;

use DateTimeImmutable;

final class StrictDate
{
    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2200;

    public static function parse(string $value, string $format): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);

        if ($parsed === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        $year = (int) $parsed->format('Y');

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return $parsed;
    }

    public static function matches(string $value, string $format): bool
    {
        return self::parse($value, $format) !== null;
    }
}
