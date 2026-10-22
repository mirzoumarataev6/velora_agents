<?php

namespace App\Services\Dataset;

final class Transliterator
{
    public const MAX_CANDIDATES = 32;

    protected const MAX_LENGTH = 40;

    protected const MAP = [
        'shch' => ['щ'],
        'shh' => ['щ'],
        'sch' => ['щ'],
        'zch' => ['щ'],

        'iya' => ['ия'],
        'iyu' => ['ию'],
        'iye' => ['ие'],
        'iyo' => ['иё'],

        'ch' => ['ч'],
        'sh' => ['ш'],
        'zh' => ['ж'],
        'kh' => ['х', 'ҳ'],
        'gh' => ['ғ', 'г'],
        'ts' => ['ц'],
        'tc' => ['ц'],
        'ya' => ['я'],
        'ja' => ['я'],
        'ia' => ['я', 'иа'],
        'yu' => ['ю'],
        'ju' => ['ю'],
        'iu' => ['ю', 'иу'],
        'yo' => ['ё'],
        'jo' => ['ё'],
        'io' => ['ё', 'ио'],
        'ye' => ['е'],
        'je' => ['е'],
        'yi' => ['ый'],
        'iy' => ['ий'],

        'a' => ['а'],
        'b' => ['б'],
        'c' => ['ц', 'к', 'с'],
        'd' => ['д'],
        'e' => ['е', 'э'],
        'f' => ['ф'],
        'g' => ['г', 'ғ'],
        'h' => ['х', 'ҳ'],
        'i' => ['и'],
        'j' => ['ж', 'й'],
        'k' => ['к'],
        'l' => ['л'],
        'm' => ['м'],
        'n' => ['н'],
        'o' => ['о'],
        'p' => ['п'],
        'q' => ['қ', 'к'],
        'r' => ['р'],
        's' => ['с'],
        't' => ['т'],
        'u' => ['у', 'ӯ'],
        'v' => ['в'],
        'w' => ['в'],
        'x' => ['х', 'кс'],
        'y' => ['ы', 'й', 'и'],
        'z' => ['з'],
    ];

    public static function candidates(string $folded): array
    {
        $folded = trim($folded);

        if ($folded === '' || mb_strlen($folded) > self::MAX_LENGTH) {
            return [];
        }

        if (! preg_match('/^[a-z0-9 ]+$/', $folded) || ! preg_match('/[a-z]/', $folded)) {
            return [];
        }

        $forms = [$folded];

        $tight = str_replace(' ', '', $folded);

        if ($tight !== $folded && $tight !== '') {
            $forms[] = $tight;
        }

        $candidates = [];

        foreach ($forms as $form) {
            foreach (self::expand($form) as $candidate) {
                $candidates[$candidate] = true;
            }
        }

        return array_keys($candidates);
    }

    protected static function expand(string $form): array
    {
        $variants = [''];
        $length = strlen($form);

        for ($at = 0; $at < $length;) {
            [$token, $readings] = self::readingsAt($form, $at);

            $at += strlen($token);

            if (count($variants) * count($readings) > self::MAX_CANDIDATES) {
                $readings = [$readings[0]];
            }

            $next = [];

            foreach ($variants as $prefix) {
                foreach ($readings as $reading) {
                    $next[] = $prefix.$reading;
                }
            }

            $variants = $next;
        }

        return $variants;
    }

    protected static function readingsAt(string $form, int $at): array
    {
        foreach ([4, 3, 2, 1] as $width) {
            $token = substr($form, $at, $width);

            if ($token !== '' && isset(self::MAP[$token])) {
                return [$token, self::MAP[$token]];
            }
        }

        return [substr($form, $at, 1), [substr($form, $at, 1)]];
    }
}
