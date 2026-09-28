<?php

namespace App\Services\Reply;

use App\Models\Conversation;

class CustomerLocale
{
    protected const LOOKBACK = 5;

    // Создаёт определитель локали с сервисом определения языка
    public function __construct(protected LanguageDetector $languages = new LanguageDetector) {}

    // Определяет локаль, на которой нужно отвечать клиенту в этом диалоге
    public function forCustomer(Conversation $conversation): string
    {
        return $this->toLocale($this->detected($conversation))
            ?? $this->toLocale($conversation->agent?->language)
            ?? config('app.locale');
    }

    // Определяет локаль интерфейса для агента (владельца) по этому диалогу
    public function forAgent(Conversation $conversation): string
    {
        return $this->toLocale($conversation->agent?->language)
            ?? $this->toLocale($this->detected($conversation))
            ?? config('app.locale');
    }

    // Определяет язык клиента по последним текстовым сообщениям диалога, исключая команды
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

    // Преобразует код языка, определённый LanguageDetector, в код локали приложения
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
