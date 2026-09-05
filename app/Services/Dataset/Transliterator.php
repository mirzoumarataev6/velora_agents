<?php

namespace App\Services\Dataset;

/**
 * Latin spellings of Cyrillic column names, turned back into Cyrillic.
 *
 * WHY THIS EXISTS. The catalog vocabulary is written in the three languages the
 * platform serves. Real databases are not: a Russian, Tajik or Uzbek shop very
 * often has `nazvanie`, `cena`, `ostatok`, `narkh` — the same words, typed on a
 * latin keyboard because that is what the hosting panel, the CMS or the previous
 * developer allowed. Listing every latin spelling as an alias does not scale: it
 * is hundreds of entries per language, it has to be repeated for every language
 * we add, and it still misses the spelling nobody thought of.
 *
 * So this goes the other way. A latin column name is transliterated BACK to
 * Cyrillic and matched against the vocabulary that already exists. One
 * vocabulary, and every language in it gets latin support for free.
 *
 * MANY-TO-ONE, SO MANY CANDIDATES. Transliteration is lossy in the direction we
 * need: `ц` is written c, ts or tc; `я` is ya, ia or ja; `х` is h or kh. There
 * is no way to know which convention a given developer used, so this produces
 * several candidate spellings and lets the vocabulary decide which — if any — is
 * a real word. Nothing here decides anything on its own; it only proposes
 * strings to look up.
 *
 * BOUNDED. The candidate set is a product of per-letter choices, which grows
 * exponentially, so it is capped: once the cap is in sight only the commonest
 * reading of each remaining letter is taken. A wide table cannot turn column
 * matching into a combinatorial walk.
 */
final class Transliterator
{
    /** Candidate spellings produced for one name, at most. */
    public const MAX_CANDIDATES = 32;

    /** Longer than this is a sentence, not a column name. */
    protected const MAX_LENGTH = 40;

    /**
     * Latin sequence => Cyrillic readings, commonest first.
     *
     * Multi-letter keys are matched before single letters, so `sh` is never read
     * as с+х. Within a key the FIRST reading is the one that survives the
     * candidate cap, so it is ordered by what actually turns up in shop
     * databases: `c` is ц far more often than к in a transliterated Russian
     * schema (cena, cvet), and `y` is ы (ssylka) more often than й.
     *
     * The Tajik letters (ҳ, қ, ғ, ӯ) are alternatives rather than replacements —
     * `h` is х in a Russian word and ҳ in a Tajik one, and both readings get
     * tried.
     *
     * @var array<string, string[]>
     */
    protected const MAP = [
        // Four and three letters: the digraph clusters that would otherwise be
        // taken apart by the shorter rules below.
        'shch' => ['щ'],
        'shh' => ['щ'],
        'sch' => ['щ'],
        'zch' => ['щ'],

        // The -ия / -ию endings, which are half the abstract nouns in the
        // vocabulary: категория, коллекция, продукция. They are listed whole
        // because the scan is greedy and `iy` would otherwise be taken first,
        // reading `kategoriya` as "категорийа" — a word no vocabulary has.
        'iya' => ['ия'],
        'iyu' => ['ию'],
        'iye' => ['ие'],
        'iyo' => ['иё'],

        // Two letters.
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

        // One letter.
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

    /**
     * Cyrillic readings of one already-folded latin name.
     *
     * Both spacings are offered, because a separator carries no meaning here and
     * developers disagree about it: `kol_vo` folds to "kol vo", and "колво" is
     * as plausible a reading as "кол во".
     *
     * Returns [] for anything that is not a latin word — a name already written
     * in Cyrillic needs no help, and passing it through would only invent
     * spellings for a word we can already read.
     *
     * @return array<int, string>
     */
    public static function candidates(string $folded): array
    {
        $folded = trim($folded);

        if ($folded === '' || mb_strlen($folded) > self::MAX_LENGTH) {
            return [];
        }

        // Latin letters, digits and the spaces folding left behind. A single
        // Cyrillic character anywhere means this name is already in one of the
        // vocabulary's own alphabets.
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
                // Keyed to dedupe: two spacings often converge on the same word.
                $candidates[$candidate] = true;
            }
        }

        return array_keys($candidates);
    }

    /**
     * Every reading of one latin form, within the cap.
     *
     * @return array<int, string>
     */
    protected static function expand(string $form): array
    {
        $variants = [''];
        $length = strlen($form);

        for ($at = 0; $at < $length;) {
            [$token, $readings] = self::readingsAt($form, $at);

            $at += strlen($token);

            // The cap, applied BEFORE multiplying rather than by truncating
            // afterwards: dropping candidates at the end would keep whichever
            // ones happened to be generated first, while narrowing a letter
            // keeps the commonest reading of it — which is the one worth having.
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

    /**
     * The longest mapped sequence starting at $at, and how it can be read.
     *
     * @return array{0: string, 1: string[]}
     */
    protected static function readingsAt(string $form, int $at): array
    {
        foreach ([4, 3, 2, 1] as $width) {
            $token = substr($form, $at, $width);

            if ($token !== '' && isset(self::MAP[$token])) {
                return [$token, self::MAP[$token]];
            }
        }

        // A space or a digit: kept as itself so "kol vo" stays two words and
        // "cena2" keeps its number.
        return [substr($form, $at, 1), [substr($form, $at, 1)]];
    }
}
