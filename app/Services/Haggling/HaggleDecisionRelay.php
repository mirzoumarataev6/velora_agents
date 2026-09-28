<?php

namespace App\Services\Haggling;

use App\Models\Lead;
use App\Models\Message;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\HumanizedReplySender;
use App\Services\Reply\LanguageDetector;
use Illuminate\Support\Facades\Log;
use Throwable;

class HaggleDecisionRelay
{
    protected const LANGUAGE_LOOKBACK = 5;

    // Создаёт транслятор решений по торгу с драйверами каналов и определением языка
    public function __construct(
        protected ChannelDriverManager $drivers,
        protected HumanizedReplySender $sender,
        protected LanguageDetector $languages,
    ) {}

    // Одобряет запрошенную клиентом цену и отправляет ему подтверждение
    public function approve(Lead $lead, ?float $price = null): Message
    {
        $granted = $price ?? (float) $lead->requested_price;

        $lead->forceFill([
            'approved_price' => $granted > 0 ? $granted : null,
            'status' => 'in_progress',
        ])->save();

        return $this->relay($lead, $this->approvalText($lead, $granted));
    }

    // Отклоняет запрошенную клиентом цену, при желании предлагая встречную
    public function decline(Lead $lead, ?float $counterPrice = null): Message
    {
        $lead->forceFill([
            'approved_price' => $counterPrice !== null && $counterPrice > 0 ? $counterPrice : null,
            'status' => $counterPrice !== null && $counterPrice > 0 ? 'in_progress' : 'done',
        ])->save();

        return $this->relay($lead, $this->declineText($lead, $counterPrice));
    }

    // Сохраняет решение по торгу как сообщение и отправляет его клиенту через канал
    protected function relay(Lead $lead, string $text): Message
    {
        $conversation = $lead->conversation;

        if ($conversation === null) {
            return new Message(['role' => 'assistant', 'content' => $text]);
        }

        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => $text]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        $channel = $conversation->channel;

        if ($channel !== null && $conversation->external_user_id !== null) {
            try {
                $this->sender->send(
                    $this->drivers->for($channel),
                    $channel,
                    $conversation->external_user_id,
                    $text,
                );
            } catch (Throwable $e) {
                Log::error('Could not deliver a price decision to the customer', [
                    'lead_id' => $lead->id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $message;
    }

    // Формирует текст сообщения об одобрении цены на языке клиента
    protected function approvalText(Lead $lead, float $granted): string
    {
        $language = $this->languageFor($lead);
        $price = Lead::formatPrice($granted);
        $product = $lead->items;

        $line = match ($language) {
            LanguageDetector::TAJIK => $price === null
                ? 'Хабари хуш! Менеҷер нархи пешниҳодшумо розӣ шуд.'
                : "Хабари хуш! Мо метавонем нархи {$price}-ро барои шумо кунем.",
            LanguageDetector::ENGLISH => $price === null
                ? 'Good news! The manager has approved the price you asked for.'
                : "Good news! We can do {$price} for you.",
            default => $price === null
                ? 'Хорошая новость! Менеджер согласовал ту цену, которую вы просили.'
                : "Хорошая новость! Мы можем сделать такую цену для вас — {$price}.",
        };

        if ($product !== null) {
            $line .= " ({$product})";
        }

        return $line.' '.match ($language) {
            LanguageDetector::TAJIK => 'Фармоишро расмӣ кунем?',
            LanguageDetector::ENGLISH => 'Shall I place the order for you?',
            default => 'Оформляем заказ?',
        };
    }

    // Формирует текст сообщения об отказе (с встречным предложением или без) на языке клиента
    protected function declineText(Lead $lead, ?float $counterPrice): string
    {
        $language = $this->languageFor($lead);

        if ($counterPrice !== null && $counterPrice > 0) {
            $price = Lead::formatPrice($counterPrice);

            return match ($language) {
                LanguageDetector::TAJIK => 'Бо менеҷер сӯҳбат кардам: маҳз ҳамон нарх намешавад, вале мо метавонем '
                    ."{$price} пешниҳод кунем. Ин ба шумо мувофиқ аст?",
                LanguageDetector::ENGLISH => "I checked with the manager: that exact price isn't possible, but we can "
                    ."do {$price}. Would that work for you?",
                default => 'Уточнил у менеджера: именно такую цену дать не получится, но мы можем предложить '
                    ."{$price}. Как вам такой вариант?",
            };
        }

        return match ($language) {
            LanguageDetector::TAJIK => 'Бо менеҷер сӯҳбат кардам — мутаассифона, ин дафъа чунин нарх намешавад. '
                .'Бубахшед! Агар хоҳед, метавонам вариантҳои ба буҷаи шумо мувофиқро нишон диҳам.',
            LanguageDetector::ENGLISH => "I checked with the manager — unfortunately we can't do that price this "
                .'time. Sorry about that! If you like, I can show you options that fit your budget.',
            default => 'Уточнил у менеджера — к сожалению, такую цену в этот раз дать не получится. Извините! '
                .'Если хотите, подберу варианты под ваш бюджет.',
        };
    }

    // Определяет язык клиента по последним сообщениям диалога
    protected function languageFor(Lead $lead): string
    {
        $conversation = $lead->conversation;

        if ($conversation === null) {
            return LanguageDetector::RUSSIAN;
        }

        try {
            return $this->languages->forConversation(
                $conversation->messages()
                    ->where('role', 'user')
                    ->orderByDesc('id')
                    ->take(self::LANGUAGE_LOOKBACK)
                    ->pluck('content')
            ) ?? LanguageDetector::RUSSIAN;
        } catch (Throwable $e) {
            return LanguageDetector::RUSSIAN;
        }
    }
}
