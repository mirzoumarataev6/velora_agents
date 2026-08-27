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
     * Split a text query into the lowercased terms that must all match.
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
            $term = $this->escapeLike($part);
            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_slice($terms, 0, 6);
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
