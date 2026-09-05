<?php

namespace App\Services\Leads;

/**
 * Finds a phone number in what a customer wrote.
 *
 * WHY A DETECTOR RATHER THAN A FORM FIELD. The order tool takes whatever the
 * model chose to pass, and the model routinely puts a phone number in the
 * general `contact` field, or omits it entirely because the customer typed it
 * three messages ago and it "already knows" it. Neither is a reliable basis for
 * deciding whether the business can actually ring this person — and that
 * decision drives what the assistant promises them and what the owner's panel
 * shows. So the number is looked for in the text itself.
 *
 * THE FAILURE TO AVOID is a price becoming a phone number. This catalog is full
 * of four- and five-digit numbers ("14999 сомони"), and an order confirmed with
 * "мы вам позвоним" against a number that is really a price is worse than
 * asking again. Hence the digit floor: a real number here is nine digits or
 * more once separators are stripped (992 XX XXX XX XX, or a bare 9XXXXXXXX
 * mobile), and nothing shorter is accepted however phone-shaped it looks.
 */
class PhoneNumberDetector
{
    /**
     * Fewest digits that can be a phone number. Tajik mobiles are nine
     * (901234567); with the country code, twelve.
     */
    protected const MIN_DIGITS = 9;

    /** Most digits before it stops being a number and starts being an id. */
    protected const MAX_DIGITS = 15;

    /**
     * A run of digits and the punctuation people put between them. The lead "+"
     * is kept so an international number reads as one token.
     */
    protected const CANDIDATE = '/\+?[\d][\d\s\-().]{7,}\d/u';

    /**
     * The first phone number in the text, normalised, or null.
     *
     * Normalised means digits plus a leading "+" if the customer wrote one:
     * what is stored should be dialable, not a transcript of their formatting.
     */
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

    /**
     * The first phone number across several texts, newest first.
     *
     * @param  iterable<string|null>  $texts
     */
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
