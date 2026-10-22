<?php

namespace App\Services\Reply;

use App\Models\Conversation;

class CustomerLocale
{
    protected const LOOKBACK = 5;

    public function __construct(protected LanguageDetector $languages = new LanguageDetector) {}

    public function forCustomer(Conversation $conversation): string
    {
        return $this->toLocale($this->detected($conversation))
            ?? $this->toLocale($conversation->agent?->language)
            ?? config('app.locale');
    }

    public function forAgent(Conversation $conversation): string
    {
        return $this->toLocale($conversation->agent?->language)
            ?? $this->toLocale($this->detected($conversation))
            ?? config('app.locale');
    }

    protected function detected(Conversation $conversation): ?string
    {
        return $this->languages->forConversation(
            $conversation->messages()
                ->where('role', 'user')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->take(self::LOOKBACK)
                ->pluck('content')
                ->reject(fn ($text) => str_starts_with(ltrim((string) $text), '/'))
        );
    }

    protected function toLocale(?string $language): ?string
    {
        return match ($language) {
            LanguageDetector::TAJIK, 'tg' => 'tg',
            LanguageDetector::ENGLISH => 'en',
            LanguageDetector::RUSSIAN => 'ru',
            default => null,
        };
    }
}
