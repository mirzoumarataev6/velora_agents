<?php

namespace App\Services\Quality\Concerns;

use Illuminate\Support\Str;

/**
 * The matching half of a multilingual phrase detector: lower-case and squish
 * the text, then test it against the using class's PATTERNS, any one of which
 * is a match.
 *
 * Shared so that every "did the customer / the agent say X, in ru, tg or en"
 * judgement normalises text the same way. The patterns — the part that is
 * actually about language — stay with each detector.
 */
trait MatchesPatterns
{
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
