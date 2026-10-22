<?php

namespace App\Services\Reply;

class ReplySimilarity
{
    public const DUPLICATE_THRESHOLD = 0.8;

    protected const MIN_WORDS = 8;

    protected const MIN_LISTED_FIGURES = 3;

    public function containment(string $candidate, string $previous): float
    {
        $new = $this->words($candidate);
        $old = $this->words($previous);

        if ($new === [] || $old === []) {
            return 0.0;
        }

        $shared = array_intersect_key(array_flip($new), array_flip($old));

        return count($shared) / count(array_unique($new));
    }

    public function isNearDuplicate(string $candidate, string $previous): bool
    {
        if (count($this->words($candidate)) < self::MIN_WORDS) {
            return false;
        }

        return $this->containment($candidate, $previous) >= self::DUPLICATE_THRESHOLD;
    }

    public function repeatsListing(string $candidate, string $previous, array $ignore = []): bool
    {
        $ignored = array_flip($this->figures(implode(' ', $ignore)));
        $listed = array_diff_key(array_flip($this->figures($candidate)), $ignored);

        if (count($listed) < self::MIN_LISTED_FIGURES) {
            return false;
        }

        $shared = array_intersect_key($listed, array_flip($this->figures($previous)));

        return count($shared) / count($listed) >= self::DUPLICATE_THRESHOLD;
    }

    public function isRepeatOf(string $candidate, string $previous, array $ignore = []): bool
    {
        return $this->isNearDuplicate($candidate, $previous)
            || $this->repeatsListing($candidate, $previous, $ignore)
            || $this->repeatedQuestions($candidate, $previous) !== []
            || $this->isShortDuplicate($candidate, $previous);
    }

    public function repeatedQuestions(string $candidate, string $previous): array
    {
        $previousQuestions = array_map(fn ($text) => $this->words($text), $this->questions($previous));

        return array_values(array_filter($this->questions($candidate), fn ($question) => count($this->words($question)) >= 3
            && in_array($this->words($question), $previousQuestions, true)
        ));
    }

    public function withoutRepeatedQuestions(string $candidate, array $previousReplies): string
    {
        $cleaned = $candidate;
        foreach ($previousReplies as $previous) {
            foreach ($this->repeatedQuestions($cleaned, $previous) as $question) {
                $cleaned = str_replace($question, '', $cleaned);
            }
        }

        $cleaned = trim((string) preg_replace('/[\h]{2,}/u', ' ', $cleaned));

        return count($this->words($cleaned)) >= 3 ? $cleaned : $candidate;
    }

    protected function isShortDuplicate(string $candidate, string $previous): bool
    {
        $words = $this->words($candidate);

        return count($words) >= 4 && count($words) < self::MIN_WORDS
            && ! preg_match('/\p{N}/u', $candidate)
            && $words === $this->words($previous);
    }

    protected function questions(string $text): array
    {

        $sentences = preg_split('/(?<=[.!?。！？])\s+|\R/u', trim($text)) ?: [];

        return array_values(array_filter($sentences, fn ($sentence) => preg_match('/[?？][!！]*$/u', $sentence)));
    }

    protected function figures(string $text): array
    {
        return array_values(array_unique(array_filter(
            $this->words($text),
            fn (string $word) => preg_match('/^\d{3,}$/', $word) === 1,
        )));
    }

    protected function words(string $text): array
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return array_values(array_filter(
            preg_split('/\s+/u', trim($text)) ?: [],
            fn (string $word) => mb_strlen($word) > 1,
        ));
    }
}
