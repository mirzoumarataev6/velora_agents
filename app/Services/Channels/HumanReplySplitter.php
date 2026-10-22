<?php

namespace App\Services\Channels;

class HumanReplySplitter
{
    protected const BOUNDARIES = [
        ['pattern' => '/\n{2,}/u', 'glue' => "\n\n"],
        ['pattern' => '/\n/u', 'glue' => "\n"],
        ['pattern' => '/(?<=[.!?…])\s+/u', 'glue' => ' '],
    ];

    public function split(string $text, int $minLength, int $maxMessages): array
    {
        $text = trim($text);

        if ($text === '' || $maxMessages < 2 || mb_strlen($text) <= $minLength) {
            return [$text];
        }

        [$units, $glue] = $this->units($text);

        if (count($units) < 2) {
            return [$text];
        }

        return $this->pack($units, $glue, min($maxMessages, count($units)));
    }

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
