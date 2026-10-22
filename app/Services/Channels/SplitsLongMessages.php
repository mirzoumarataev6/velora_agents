<?php

namespace App\Services\Channels;

trait SplitsLongMessages
{
    protected function splitMessage(string $text, int $maxLength): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];

        while (mb_strlen($text) > $maxLength) {
            $slice = mb_substr($text, 0, $maxLength);

            $breakAt = null;
            foreach (["\n\n", "\n", '. ', ' '] as $boundary) {
                $pos = mb_strrpos($slice, $boundary);
                if ($pos !== false && $pos > $maxLength * 0.5) {
                    $breakAt = $pos + mb_strlen($boundary);
                    break;
                }
            }
            $breakAt ??= $maxLength;

            $chunks[] = rtrim(mb_substr($text, 0, $breakAt));
            $text = ltrim(mb_substr($text, $breakAt));
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }
}
