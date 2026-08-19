<?php

namespace App\Services\Channels;

/**
 * Splits a long reply the way a person breaks up a thought when typing in a
 * messenger: into a few consecutive messages, always at a meaning boundary.
 *
 * This is deliberately NOT the same concern as SplitsLongMessages, which
 * chops text at a platform's hard character limit as a last resort and will
 * break mid-sentence to do it. This one only ever splits where the text
 * already has a seam — a paragraph, a line, or a sentence end — and gives up
 * (returning the text as a single message) when there is no such seam. Short
 * replies are never touched.
 */
class HumanReplySplitter
{
    /**
     * Candidate unit boundaries, most meaningful first, each with the
     * separator used to re-join units that end up in the same message.
     *
     * @var array<int, array{pattern: string, glue: string}>
     */
    protected const BOUNDARIES = [
        // Paragraphs.
        ['pattern' => '/\n{2,}/u', 'glue' => "\n\n"],
        // Single line breaks — bullet lists, "key: value" blocks.
        ['pattern' => '/\n/u', 'glue' => "\n"],
        // Sentence ends. Requires the following character to be whitespace, so
        // decimals ("1.5"), abbreviations without a space, and URLs stay whole.
        ['pattern' => '/(?<=[.!?…])\s+/u', 'glue' => ' '],
    ];

    /**
     * @return string[] One entry per message to send, in order. Always at
     *                  least one entry; never more than $maxMessages.
     */
    public function split(string $text, int $minLength, int $maxMessages): array
    {
        $text = trim($text);

        if ($text === '' || $maxMessages < 2 || mb_strlen($text) <= $minLength) {
            return [$text];
        }

        [$units, $glue] = $this->units($text);

        // A long reply with no internal seam (one unbroken sentence) stays one
        // message — splitting it would cut mid-sentence, which is the one thing
        // this class exists to avoid.
        if (count($units) < 2) {
            return [$text];
        }

        return $this->pack($units, $glue, min($maxMessages, count($units)));
    }

    /**
     * Break the text at the most meaningful boundary that actually occurs in
     * it, falling back to finer ones.
     *
     * @return array{0: string[], 1: string}
     */
    protected function units(string $text): array
    {
        foreach (self::BOUNDARIES as $boundary) {
            $parts = preg_split($boundary['pattern'], $text) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));

            if (count($parts) > 1) {
                return [$parts, $boundary['glue']];
            }
        }

        return [[$text], ' '];
    }

    /**
     * Greedily group units into $count messages of roughly equal length, so a
     * reply never arrives as one long message followed by a three-word
     * afterthought. The target length is recomputed after each message from
     * what is left, which keeps the last message from absorbing the remainder.
     *
     * @param  string[]  $units
     * @return string[]
     */
    protected function pack(array $units, string $glue, int $count): array
    {
        $remaining = array_sum(array_map('mb_strlen', $units));
        $target = (int) ceil($remaining / $count);

        $messages = [];
        $current = [];
        $currentLength = 0;
        $unitsLeft = count($units);

        foreach ($units as $unit) {
            $current[] = $unit;
            $currentLength += mb_strlen($unit);
            $remaining -= mb_strlen($unit);
            $unitsLeft--;

            $messagesLeft = $count - count($messages) - 1;

            // Close this message once it has reached its share, and force it
            // closed when the units left are exactly what the later messages
            // need — otherwise the first message would swallow the whole reply.
            $mustClose = $messagesLeft >= 1 && $unitsLeft === $messagesLeft;
            $reachedShare = $messagesLeft >= 1 && $unitsLeft > $messagesLeft && $currentLength >= $target;

            if ($mustClose || $reachedShare) {
                $messages[] = implode($glue, $current);
                $current = [];
                $currentLength = 0;
                $target = (int) ceil($remaining / $messagesLeft);
            }
        }

        if ($current !== []) {
            $messages[] = implode($glue, $current);
        }

        return $messages;
    }
}
