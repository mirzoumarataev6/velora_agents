<?php

namespace App\Services\Reply;

use App\Models\Conversation;

/**
 * Which app locale (ru / tg / en) to use for a canned string the customer
 * sees — a rating prompt, a confirmation, a default greeting.
 *
 * Uses the same detector, on the same recent customer messages, as the reply
 * pipeline, so a customer who has had the whole conversation in Tajik is not
 * suddenly addressed in Russian by a fixed string.
 */
class CustomerLocale
{
    /** Matches AgentReplyService::LANGUAGE_LOOKBACK. */
    protected const LOOKBACK = 5;

    public function __construct(protected LanguageDetector $languages = new LanguageDetector) {}

    /**
     * The language the customer has been writing in, else the agent's
     * configured one, else the app default.
     */
    public function forCustomer(Conversation $conversation): string
    {
        return $this->toLocale($this->detected($conversation))
            ?? $this->toLocale($conversation->agent?->language)
            ?? config('app.locale');
    }

    /**
     * The agent's configured language first. For what the BUSINESS says
     * unprompted — a greeting — where the owner's choice outranks a guess.
     */
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
                // A bot command is not language: "/start" would read as English.
                ->reject(fn ($text) => str_starts_with(ltrim((string) $text), '/'))
        );
    }

    /** Agent settings and the detector use 'tj' for Tajik; the lang directory is 'tg'. */
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
