<?php

namespace App\Services\Dataset;

final class SearchSkeleton
{
    private const CYRILLIC_TO_LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'ӣ' => 'i', 'й' => 'i', 'к' => 'k', 'қ' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ӯ' => 'u', 'ф' => 'f', 'х' => 'h', 'ҳ' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ҷ' => 'j', 'ш' => 'sh',
        'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::CYRILLIC_TO_LATIN);
        $text = (string) preg_replace('/[^a-z0-9]+/', '', $text);
        $text = strtr($text, ['ck' => 'k', 'c' => 'k', 'q' => 'k', 'oo' => 'u', 'ph' => 'f', 'w' => 'v', 'y' => 'i', 'x' => 'ks']);

        return (string) preg_replace('/(.)\1+/', '$1', $text);
    }
}
