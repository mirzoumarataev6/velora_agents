<?php

namespace App\Services\Reply;

class ReplySimilarity
{
    public const DUPLICATE_THRESHOLD = 0.8;

    protected const MIN_WORDS = 8;

    protected const MIN_LISTED_FIGURES = 3;

    // Вычисляет долю слов нового ответа, уже встречавшихся в предыдущем
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

    // Определяет, почти дословно ли новый ответ повторяет предыдущий
    public function isNearDuplicate(string $candidate, string $previous): bool
    {
        if (count($this->words($candidate)) < self::MIN_WORDS) {
            return false;
        }

        return $this->containment($candidate, $previous) >= self::DUPLICATE_THRESHOLD;
    }

    // Определяет, повторяет ли ответ те же числовые данные, что были перечислены ранее
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

    // Определяет, является ли ответ повтором предыдущего (дословно или по данным)
    public function isRepeatOf(string $candidate, string $previous, array $ignore = []): bool
    {
        return $this->isNearDuplicate($candidate, $previous)
            || $this->repeatsListing($candidate, $previous, $ignore);
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
