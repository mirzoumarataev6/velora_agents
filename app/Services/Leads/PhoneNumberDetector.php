<?php

namespace App\Services\Leads;

class PhoneNumberDetector
{
    protected const MIN_DIGITS = 9;

    protected const MAX_DIGITS = 15;

    protected const CANDIDATE = '/\+?[\d][\d\s\-().]{7,}\d/u';

    public function find(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (! preg_match_all(self::CANDIDATE, $text, $matches)) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';
            $length = strlen($digits);

            if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
                continue;
            }

            return (str_starts_with(trim($candidate), '+') ? '+' : '').$digits;
        }

        return null;
    }

    public function findIn(iterable $texts): ?string
    {
        foreach ($texts as $text) {
            $found = $this->find($text);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
