<?php

namespace App\Services\Reply;

/**
 * How much of one reply is already in another.
 *
 * The reported failure was not the model being unhelpful — it was the model
 * sending the SAME four-product list a second time in answer to "так да или
 * нет". The prompt can ask it not to do that; only a measurement can tell
 * whether it complied, which is what lets the pipeline catch the repeat and ask
 * again instead of shipping it to the customer.
 *
 * Measured as containment rather than as a symmetric similarity: the new reply
 * is judged by how much of IT is recycled. A short "да, всё верно" that happens
 * to reuse words from a long previous answer is not a repeat, and a symmetric
 * score would wrongly forgive a long repeat that adds one new sentence.
 */
class ReplySimilarity
{
    /**
     * At or above this share of recycled words, the reply reads to a customer as
     * the previous one sent again.
     *
     * 0.8 rather than 1.0 because a verbatim repeat is not the usual shape: the
     * model re-runs the same search and re-renders the same facts with the
     * connective words shuffled, which lands in the eighties.
     */
    public const DUPLICATE_THRESHOLD = 0.8;

    /**
     * Replies this short are not compared at all. "Да, конечно" after "Да,
     * конечно" is two people agreeing, not a bot looping, and short texts hit
     * high overlap by coincidence.
     */
    protected const MIN_WORDS = 8;

    /**
     * The fewest repeated prices that make a repeated listing. Two is a customer
     * asking "which of these two is better" and being answered about both.
     */
    protected const MIN_LISTED_FIGURES = 3;

    /**
     * The share of $candidate's words that also appear in $previous, from 0 to 1.
     */
    public function containment(string $candidate, string $previous): float
    {
        $new = $this->words($candidate);
        $old = $this->words($previous);

        if ($new === [] || $old === []) {
            return 0.0;
        }

        $shared = array_intersect_key(array_flip($new), array_flip($old));

        return count($shared) / count(array_unique($new));
    }

    /**
     * Whether $candidate would read as the customer being sent $previous again.
     */
    public function isNearDuplicate(string $candidate, string $previous): bool
    {
        if (count($this->words($candidate)) < self::MIN_WORDS) {
            return false;
        }

        return $this->containment($candidate, $previous) >= self::DUPLICATE_THRESHOLD;
    }

    /**
     * Whether $candidate lists the same products as $previous, however it is
     * worded around them.
     *
     * Word containment alone missed the reported loop: the model rewrote every
     * framing sentence in Tajik while the three MacBooks and their prices stayed
     * identical, which scored 0.57-0.74 — "different", by words, and exactly
     * the same answer to the customer. The prices are the substance of a
     * listing, so they are compared on their own.
     *
     * Figures the customer supplied (their budget, "6000 то 7000") are ignored:
     * a reply quoting the customer's own numbers back is not the assistant
     * repeating itself.
     *
     * @param  array<int, string>  $ignore  Texts whose figures do not count — the customer's own messages.
     */
    public function repeatsListing(string $candidate, string $previous, array $ignore = []): bool
    {
        $ignored = array_flip($this->figures(implode(' ', $ignore)));
        $listed = array_diff_key(array_flip($this->figures($candidate)), $ignored);

        if (count($listed) < self::MIN_LISTED_FIGURES) {
            return false;
        }

        $shared = array_intersect_key($listed, array_flip($this->figures($previous)));

        return count($shared) / count($listed) >= self::DUPLICATE_THRESHOLD;
    }

    /**
     * Either kind of repeat: the same words, or the same products.
     *
     * @param  array<int, string>  $ignore  See repeatsListing().
     */
    public function isRepeatOf(string $candidate, string $previous, array $ignore = []): bool
    {
        return $this->isNearDuplicate($candidate, $previous)
            || $this->repeatsListing($candidate, $previous, $ignore);
    }

    /**
     * Standalone numbers of three or more digits — prices, in practice. "8GB"
     * and "256GB" are single tokens and deliberately not figures: every laptop
     * has them.
     *
     * @return string[]
     */
    protected function figures(string $text): array
    {
        return array_values(array_unique(array_filter(
            $this->words($text),
            fn (string $word) => preg_match('/^\d{3,}$/', $word) === 1,
        )));
    }

    /**
     * The comparable words of a reply: lowercased, punctuation dropped, and
     * one-character tokens discarded as noise.
     *
     * Numbers are kept deliberately — a price repeated is the most conspicuous
     * part of a repeated answer.
     *
     * @return string[]
     */
    protected function words(string $text): array
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return array_values(array_filter(
            preg_split('/\s+/u', trim($text)) ?: [],
            fn (string $word) => mb_strlen($word) > 1,
        ));
    }
}
