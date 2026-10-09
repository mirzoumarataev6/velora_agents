<?php

namespace App\Services\Dataset;

/**
 * Folds a word to a script- and spelling-insensitive skeleton, so a Latin
 * brand name and its Cyrillic loanword spelling compare equal: "MacBook" and
 * "макбук" both fold to "makbuk", "VICTUS" and "виктус" both fold to "viktus".
 *
 * This is a skeleton of SOUND, not of letters: Russian/Tajik spell a loanword
 * the way it is pronounced ("макбук", not a letter-for-letter transliteration
 * of "MacBook"), so a positional Latin<->Cyrillic transliteration table (see
 * Transliterator, which solves a different problem: matching a known header
 * vocabulary) cannot bridge the two. The replacements below exist to collapse
 * exactly the respellings English orthography introduces: a hard "c" sounds
 * like "k", doubled vowels collapse to one, "ph" sounds like "f".
 *
 * Shared by the two places that need the same comparison: ProductSearchService
 * (matching a customer's "exclude" words against fetched rows) and
 * DatasetProduct (building the stored search_text haystack a LIKE query
 * matches against).
 */
final class SearchSkeleton
{
    private const CYRILLIC_TO_LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'ӣ' => 'i', 'й' => 'i', 'к' => 'k', 'қ' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ӯ' => 'u', 'ф' => 'f', 'х' => 'h', 'ҳ' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ҷ' => 'j', 'ш' => 'sh',
        'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    // Сводит слово к грубому "скелету" из латинских букв для нечувствительного к письму и опечаткам сравнения
    public static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::CYRILLIC_TO_LATIN);
        $text = (string) preg_replace('/[^a-z0-9]+/', '', $text);
        $text = strtr($text, ['ck' => 'k', 'c' => 'k', 'q' => 'k', 'oo' => 'u', 'ph' => 'f', 'w' => 'v', 'y' => 'i', 'x' => 'ks']);

        return (string) preg_replace('/(.)\1+/', '$1', $text);
    }
}
