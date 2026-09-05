<?php

namespace App\Services\Reply;

/**
 * Whether the customer's latest message is a SHORT REFERENTIAL FOLLOW-UP: one
 * that means nothing on its own and only makes sense against the reply just
 * before it — "да", "так да или нет", "это все?", "а подешевле?", "первый".
 *
 * WHY THIS IS ITS OWN DECISION. These messages are where the assistant most
 * reliably fails, and the failure is specific: the message carries almost no
 * content of its own, so retrieval and the catalog search both key off the last
 * substantive thing said — and the model, handed the same context it had a turn
 * ago, writes the same answer again. The customer asked "так да или нет" and got
 * the product list back verbatim.
 *
 * Naming the case explicitly is what lets the prompt say something useful about
 * it ("answer THIS question, do not restate what you already sent") and lets the
 * pipeline check afterwards whether the model actually did.
 *
 * Tuned towards saying no. A false positive tells the model not to repeat itself
 * on a message where repetition was never a risk, which costs nothing; treating
 * a real question as a follow-up would be worse, so anything with substance of
 * its own is rejected.
 */
class FollowUpDetector
{
    /**
     * Longer than this and the message carries its own subject. Deliberately
     * short: "а есть такие же но с доставкой?" is a real question.
     */
    protected const MAX_LENGTH = 40;

    /** Words after which a message is no longer "short". */
    protected const MAX_WORDS = 6;

    /**
     * Messages that are ONLY a reference back — a confirmation, a pick from a
     * list the assistant just gave, or a nudge for an answer it did not give.
     *
     * Matched against the whole message once it has been folded, so "Так да или
     * нет?" and "так да или нет" are the same string here.
     */
    protected const REFERENTIAL = [
        // Yes/no and the nudge for one.
        'да', 'нет', 'ага', 'угу', 'ок', 'окей', 'хорошо', 'ладно', 'понятно', 'понял', 'поняла',
        'так да или нет', 'да или нет', 'ну так', 'и', 'ну и', 'так', 'ну',
        'yes', 'no', 'ok', 'okay', 'sure', 'right', 'yes or no', 'so yes or no',
        'ҳа', 'ха', 'не', 'хуб', 'майлаш',
        // "Is that everything?" and its neighbours.
        'это все', 'это всё', 'и все', 'и всё', 'всё', 'все', 'больше нет', 'это всё что есть',
        'что еще', 'что ещё', 'еще', 'ещё', 'дальше', 'а еще', 'а ещё',
        'is that all', 'that all', 'anything else', 'more',
        'ҳамин', 'хамин', 'ҳамааш', 'хамааш',
        // Picking one of the options just listed.
        'первый', 'второй', 'третий', 'последний', 'этот', 'тот', 'первое', 'второе',
        'first', 'second', 'third', 'the first one', 'this one', 'that one',
        // Asking the same thing again.
        'а точнее', 'точнее', 'конкретнее', 'а именно', 'например', 'почему', 'зачем', 'а что',
        'why', 'why not', 'how so', 'which one',
    ];

    /**
     * Openings that make a short message a follow-up whatever comes after them:
     * "а дешевле?", "и сколько?", "а если два?". The leading conjunction is the
     * signal — it grammatically attaches the message to the previous turn.
     */
    protected const REFERENTIAL_PREFIXES = [
        'а ', 'и ', 'ну ', 'так ', 'значит ', 'то есть ',
        'and ', 'so ', 'but ', 'then ',
    ];

    /**
     * True when this message should be answered as a continuation rather than
     * as a fresh question.
     *
     * $hasPriorReply is required rather than assumed: the very first message of
     * a conversation cannot refer back to anything, and "да" as an opener is a
     * customer answering a greeting, not continuing an answer.
     */
    public function isShortFollowUp(string $message, bool $hasPriorReply): bool
    {
        if (! $hasPriorReply) {
            return false;
        }

        $text = $this->fold($message);

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return false;
        }

        if (in_array($text, self::REFERENTIAL, true)) {
            return true;
        }

        if (count(preg_split('/\s+/u', $text) ?: []) > self::MAX_WORDS) {
            return false;
        }

        foreach (self::REFERENTIAL_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, strip the punctuation that only carries tone ("Так да или
     * нет?!" and "так да или нет" are one message), collapse whitespace.
     */
    protected function fold(string $message): string
    {
        $text = mb_strtolower(trim($message));
        $text = (string) preg_replace('/[!?.,;:…"«»\-—]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
