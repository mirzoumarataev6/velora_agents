<?php

namespace App\Services\Dataset;

trait NormalizesSearchTerms
{
    protected const MIN_STEM_LENGTH = 4;

    protected const SUFFIXES = [
        'ыми', 'ими', 'ями', 'ами', 'ого', 'его', 'ому', 'ему', 'ых', 'их',
        'ые', 'ие', 'ый', 'ий', 'ой', 'ей', 'ая', 'яя', 'ое', 'ее', 'ов', 'ев',
        'ам', 'ям', 'ах', 'ях', 'ом', 'ем', 'ью', 'ии', 'ия',
        'а', 'я', 'и', 'ы', 'е', 'у', 'ю', 'о', 'ь',
        'ies', 'es', 's',
    ];

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

    protected function categoryTerms(string $category): array
    {
        return $this->terms($category);
    }

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

    protected function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], '', $value);
    }
}
