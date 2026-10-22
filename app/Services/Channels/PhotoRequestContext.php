<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Dataset\SearchSkeleton;
use App\Services\Reply\LanguageDetector;

class PhotoRequestContext
{
    public function __construct(private ImageRequestDetector $intent, private LanguageDetector $languages) {}

    public function resolve(Conversation $conversation, Message $user): array
    {
        $history = $conversation->messages()->where('id', '<', $user->id)
            ->orderByDesc('id')->limit(24)->get(['role', 'content', 'meta', 'created_at']);
        $latest = $history->firstWhere('role', 'assistant');
        $choices = [];
        $evidence = [];
        $context = '';
        foreach ($history as $message) {
            if ($message->role === 'user' && preg_match('/^\/start(?:\s|$)/u', $message->content)) {
                break;
            }
            if ($message->role !== 'assistant') {
                continue;
            }
            $listed = $message->meta['photo_choices'] ?? $this->listedProducts($message->content);
            if ($listed !== []) {
                $choices = $listed;

                $evidence = array_intersect_key($message->meta ?? [], array_flip(['product_image_candidates', 'retrieved_chunks']));
                $context = $message->meta['photo_context'] ?? $message->content;
                break;
            }
        }

        $text = trim($user->content);
        $ordinal = $this->ordinal($text);
        $model = $ordinal !== null ? ($choices[$ordinal]['name'] ?? '') : '';

        $ambiguous = [];
        if ($model === '' && $choices !== []) {
            $matches = $this->aliasMatches($text, $choices);
            if (count($matches) === 1) {

                $rows = array_values(array_filter($choices, fn ($c) => $c['name'] === $matches[0]));
                if (count($rows) > 1) {
                    $ambiguous = $rows;
                } else {
                    $model = $matches[0];
                }
            } elseif (count($matches) > 1) {
                $ambiguous = array_values(array_filter($choices, fn ($c) => in_array($c['name'], $matches, true)));
            }
        }
        $model = $model !== '' ? $model : $this->explicitModel($text);
        $pending = ($latest?->meta['photo_status'] ?? null) === 'clarify'
            && $latest->created_at->gt(now()->subMinutes(30));

        if ($model === '' && preg_match('/^\s*\d[\d\s]*(?:\s*(?:сомони|сомонӣ))?\s*$/iu', $text)) {
            $price = preg_replace('/\D/u', '', $text);
            $matches = array_filter($choices, fn ($c) => ($c['price'] ?? null) === $price);
            if (count($matches) === 1) {
                $model = array_values($matches)[0]['name'];
            }
        }
        if ($model === '' && count(array_unique(array_column($choices, 'name'))) === 1
            && preg_match('/\b(?:его|её|ее|они|их|этот|эту|it|those|that)\b/iu', $text)) {
            $model = $choices[0]['name'];
        }

        $all = $pending && $choices !== [] && preg_match('/^\s*(?:все|всё|all|hammasi|ҳама)\s*[.!]?\s*$/iu', $text) === 1;

        $newQuestion = preg_match('/(?:сколько|цен[ауые]|стоит|достав|купить|заказ|оплат|how much|price|delivery|order)/iu', $text) === 1;
        $selection = ($ordinal !== null && ! $newQuestion) || ($model !== '' && $this->normalize($text) === $this->normalize($model))
            || preg_match('/^\s*\d[\d\s]*(?:\s*(?:сомони|сомонӣ))?\s*$/iu', $text);
        $ambiguous = $newQuestion ? [] : $ambiguous;
        $all = $all && ! $newQuestion;

        $lastPhotoModel = (string) ($latest?->meta['photo_model'] ?? '');
        $recentPhotoTurn = $latest !== null
            && in_array($latest->meta['photo_status'] ?? null, ['sent', 'missing', 'failed'], true)
            && $latest->created_at->gt(now()->subMinutes(30));

        $isNudge = $model === '' && $ambiguous === [] && ! $all && $recentPhotoTurn && $lastPhotoModel !== ''
            && preg_match(self::NUDGE_PATTERN, $text) === 1;
        if ($isNudge) {
            $model = $lastPhotoModel;
        }

        return [
            'requested' => $this->intent->requested($text) || ($pending && ($selection || $ambiguous !== [] || $all)) || $isNudge,
            'model' => $model,
            'choices' => $choices,
            'ambiguous' => $ambiguous,
            'all' => $all,
            'evidence' => $evidence,
            'context' => $context,

            'locale' => $pending && $selection ? ($latest->meta['photo_locale'] ?? match ($this->languages->detect($latest->content)) {
                'tj' => 'tg', 'ru' => 'ru', 'en' => 'en', default => null,
            }) : null,
        ];
    }

    private const NUDGE_PATTERN = '/\b(?:алл?оо*|ау|почему\s+(?:я\s+)?не\s+виж[ау]|не\s+виж[ау]\s+фото|не\s+пришл\w*|не\s+получ\w*|не\s+доставлен\w*)\b/iu';

    private function aliasMatches(string $text, array $choices): array
    {
        $foldedText = $this->foldedWords($text);
        if ($foldedText === '') {
            return [];
        }

        $names = array_unique(array_column($choices, 'name'));
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $matches = [];
        foreach ($names as $name) {
            $foldedName = $this->foldedWords($name);
            $nameInText = str_contains(' '.$foldedText.' ', ' '.$foldedName.' ');
            $textInName = mb_strlen($foldedText) >= 2
                && str_contains(' '.$foldedName.' ', ' '.$foldedText.' ');
            if ($nameInText || $textInName) {
                $matches[] = $name;
            }
        }

        return $matches;
    }

    private function foldedWords(string $text): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return implode(' ', array_map(SearchSkeleton::fold(...), $matches[0] ?? []));
    }

    private function ordinal(string $text): ?int
    {
        foreach (['перв(?:ый|ого|ую|ое)|first', 'втор(?:ой|ого|ую|ое)|second', 'трет(?:ий|ьего|ью|ье)|third', 'четв[её]рт(?:ый|ого|ую|ое)|fourth', 'пят(?:ый|ого|ую|ое)|fifth'] as $index => $pattern) {
            if (preg_match('/\b(?:'.$pattern.')\b/iu', $text)) {
                return $index;
            }
        }
        if (preg_match('/^(?:фото\s*)?[#№]?\s*([1-9]|1\d|20)\s*$/iu', trim($text), $match)) {
            return (int) $match[1] - 1;
        }

        return null;
    }

    private function listedProducts(string $text): array
    {
        $choices = [];
        foreach (preg_split('/\R/u', str_replace(['**', '\\.'], ['', '.'], $text)) ?: [] as $line) {
            if (preg_match('/^\s*\d+[.)]\s+(.+?)\s+[—–-]\s*(.*)$/u', $line, $match)) {
                $name = trim($match[1]);
                if (mb_strlen($name) > 120) {
                    continue;
                }
                preg_match('/^(\d[\d\s\x{00a0}]*)/u', $match[2], $price);
                $choices[] = ['name' => $name, 'price' => preg_replace('/\D/u', '', $price[1] ?? '')];
            }
        }

        return array_slice($choices, 0, 20);
    }

    private function explicitModel(string $text): string
    {
        $text = preg_replace('/\b(?:макбук|макбука|macbook)\b/iu', 'Macbook', $text);
        $text = preg_replace('/\b(?:эйр|аир)\b/iu', 'Air', $text);
        $text = preg_replace('/\bнео\b/iu', 'Neo', $text);
        $text = preg_replace('/\bомен\b/iu', 'OMEN', $text);
        $text = preg_replace('/\b(?:show|photo|picture|image|of|the|please|laptop|notebook)\b/i', ' ', $text);
        preg_match_all('/\b[A-Za-z][A-Za-z0-9-]*(?:[ \t]+[A-Za-z0-9][A-Za-z0-9.-]*){0,4}/u', $text, $matches);
        foreach ($matches[0] ?? [] as $name) {
            if (preg_match('/\d/u', $name)) {
                return rtrim(trim($name), '.,');
            }
        }

        return '';
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/макбук\p{L}*/u', 'macbook', $text);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
