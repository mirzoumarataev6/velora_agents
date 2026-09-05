<?php

namespace App\Services\Dataset;

/**
 * Turning the model's free text into the terms a source may match on.
 *
 * Shared by both sources so "red winter jacket" narrows the same way whether it
 * lands on an imported table or a shop's live one.
 */
trait NormalizesSearchTerms
{
    /**
     * Shortest a term may be reduced to. Below this a stem stops being a word
     * and starts being a substring that matches half the catalog.
     */
    protected const MIN_STEM_LENGTH = 4;

    /**
     * Inflectional endings stripped before matching, longest first.
     *
     * WHY. A catalog is written in nominative singular — "Ноутбук (игровой)",
     * "Куртка" — and customers do not write that way: they ask for "игровые
     * ноутбуки", "куртки подешевле", "laptops". Matched literally, the plural
     * form of a word the catalog plainly contains finds nothing, and the agent
     * tells the customer the shop does not stock it. Cutting the ending off both
     * makes "ноутбуки" find "ноутбук" and leaves "ноутбук" finding itself, since
     * the match is a substring one either way.
     *
     * This is deliberately not a real morphological stemmer. It has to run on
     * every search, in three languages, and its failure mode has to be a slightly
     * wider match rather than a wrong one — so it only removes endings, never
     * rewrites a stem, and never cuts a word below MIN_STEM_LENGTH.
     *
     * @var string[]
     */
    protected const SUFFIXES = [
        // Russian: adjective and noun endings, longest first so "игровыми" loses
        // "ыми" rather than just "и".
        'ыми', 'ими', 'ями', 'ами', 'ого', 'его', 'ому', 'ему', 'ых', 'их',
        'ые', 'ие', 'ый', 'ий', 'ой', 'ей', 'ая', 'яя', 'ое', 'ее', 'ов', 'ев',
        'ам', 'ям', 'ах', 'ях', 'ом', 'ем', 'ью', 'ии', 'ия',
        'а', 'я', 'и', 'ы', 'е', 'у', 'ю', 'о', 'ь',
        // English plurals.
        'ies', 'es', 's',
    ];

    /**
     * Split a text query into the lowercased stems that must all match.
     *
     * Returns an empty list when nothing usable survives — a query of "%" folds
     * to nothing once wildcards are stripped, and the caller turns that into a
     * no-match rather than the LIKE '%%' that would return the whole catalog.
     *
     * @return string[]
     */
    protected function terms(string $query): array
    {
        $parts = preg_split('/\s+/u', mb_strtolower($query)) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            $term = $this->stem($this->escapeLike($part));
            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 6);
    }

    /**
     * The stems a category filter must ALL match against a category name.
     *
     * Split rather than matched whole, because the model states the category the
     * way the customer said it and the shop wrote it its own way: "игровые
     * ноутбуки" has to find "Ноутбук (игровой)", which no single substring of
     * the filter does. Requiring every stem keeps that from turning into a match
     * on any category that happens to share one word.
     *
     * @return string[]
     */
    protected function categoryTerms(string $category): array
    {
        return $this->terms($category);
    }

    /**
     * Strip one inflectional ending from a word, if doing so leaves a real stem.
     */
    protected function stem(string $word): string
    {
        if (mb_strlen($word) <= self::MIN_STEM_LENGTH) {
            return $word;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }

            $stem = mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));

            if (mb_strlen($stem) >= self::MIN_STEM_LENGTH) {
                return $stem;
            }
        }

        return $word;
    }

    /**
     * Strip LIKE wildcards out of a value rather than escaping them.
     *
     * Escaping would work, but it needs an explicit ESCAPE clause to behave the
     * same on MySQL, PostgreSQL and SQLite, and none of '%', '_' or '\' is a
     * character a product query legitimately needs. Removing them makes a
     * model-supplied '%' — which would otherwise match the entire catalog —
     * inert everywhere.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], '', $value);
    }
}
