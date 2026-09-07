<?php

namespace App\Services\Haggling;

use App\Models\Lead;
use App\Models\Message;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\HumanizedReplySender;
use App\Services\Reply\LanguageDetector;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries the owner's answer back into the customer's conversation.
 *
 * The customer was told "я уточню у менеджера и вернусь к вам". That sentence
 * is a promise with a deadline the customer sets, not us: if the owner clicks
 * approve and nothing reaches the chat, the shop has quietly lost the sale it
 * just agreed to. So the relay is part of the decision, not a follow-up task.
 *
 * The wording is templated rather than generated. Three reasons: the owner's
 * answer is a commitment about money and must not be paraphrased by a model; a
 * decline has to stay polite even when the provider is down; and one API call
 * per click is a cost with nothing to show for it. The customer's language is
 * detected from what they actually wrote — the same detector the reply pipeline
 * uses — so the templates still arrive in Russian, Tajik or English as
 * appropriate.
 */
class HaggleDecisionRelay
{
    /** How far back to look for the language the customer is writing in. */
    protected const LANGUAGE_LOOKBACK = 5;

    public function __construct(
        protected ChannelDriverManager $drivers,
        protected HumanizedReplySender $sender,
        protected LanguageDetector $languages,
    ) {}

    /**
     * The owner said yes — at the price the customer asked for, or at a counter
     * the owner typed in instead.
     */
    public function approve(Lead $lead, ?float $price = null): Message
    {
        $granted = $price ?? (float) $lead->requested_price;

        $lead->forceFill([
            'approved_price' => $granted > 0 ? $granted : null,
            // Approved, not finished: somebody still has to fulfil the order.
            'status' => 'in_progress',
        ])->save();

        return $this->relay($lead, $this->approvalText($lead, $granted));
    }

    /**
     * The owner said no — optionally naming a price they WOULD do, which turns
     * a dead end back into a negotiation.
     */
    public function decline(Lead $lead, ?float $counterPrice = null): Message
    {
        $lead->forceFill([
            'approved_price' => $counterPrice !== null && $counterPrice > 0 ? $counterPrice : null,
            // A counter is still a live conversation; a flat no is finished.
            'status' => $counterPrice !== null && $counterPrice > 0 ? 'in_progress' : 'done',
        ])->save();

        return $this->relay($lead, $this->declineText($lead, $counterPrice));
    }

    /**
     * Persist the relayed sentence as an ordinary assistant turn and deliver it
     * on whatever channel the conversation came in on.
     *
     * Persisted first, and delivery failures are logged rather than thrown: the
     * owner's decision is recorded either way, and a transcript that shows what
     * the shop decided is more useful to them than an error page. Playground
     * conversations have no channel and simply get the message in the thread.
     */
    protected function relay(Lead $lead, string $text): Message
    {
        $conversation = $lead->conversation;

        if ($conversation === null) {
            // The chat was pruned. There is nobody to tell; the decision still
            // stands on the lead row, which is what the owner will work from.
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

    /**
     * The language the customer has been writing in, so the answer arrives in
     * the language the question was asked in. Russian when there is no signal —
     * it is the language the rest of the panel is in.
     */
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
