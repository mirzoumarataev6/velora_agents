<?php

namespace App\Services\Quality\Concerns;

use Illuminate\Support\Str;

trait MatchesPatterns
{
    // Проверяет, соответствует ли текст одному из шаблонов класса
    public function matches(string $text): bool
    {
        $text = Str::of($text)->lower()->squish()->toString();

        if ($text === '') {
            return false;
        }

        foreach (static::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
