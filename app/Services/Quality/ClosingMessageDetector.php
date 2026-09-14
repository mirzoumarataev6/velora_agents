<?php

namespace App\Services\Quality;

use App\Services\Quality\Concerns\MatchesPatterns;

/**
 * Is the customer wrapping up — "спасибо", "Фахмо рахмат", "got it, thanks"?
 *
 * One of FeedbackCadence's end-of-conversation signals. The whole message has
 * to be made of closing words: "спасибо, а доставка сколько?" is a customer
 * still mid-flow, and asking them to rate a reply at that point is exactly
 * what the cadence exists to avoid.
 *
 * Bare agreement ("ок", "хорошо", "да") is deliberately absent. It is how a
 * customer answers "оформить заказ?", which is the middle of a conversation,
 * not the end of one.
 *
 * Tajik forms are matched with and without the Tajik letters, because most
 * customers type them on a Russian keyboard: "фахмо" as often as "фаҳмо".
 */
class ClosingMessageDetector
{
    use MatchesPatterns;

    /** What may sit between closing words: whitespace, punctuation, emoji. */
    protected const SEPARATOR = '[\s,.!?;:()\-—…\p{So}\p{Sk}\x{FE0F}\x{200D}]';

    protected const CLOSERS = '(?:'
        // Russian
        .'спасибо(?:\s+(?:большое|огромное))?|спс|благодарю|понятно|ясно|вс[её]\s+(?:понятно|ясно)'
        .'|отлично|до\s+свидания|всего\s+доброго|пока'
        // Tajik
        .'|ра[хҳ]мат(?:\s+(?:калон|бисёр))?|ташакк?ур|сипос|мерси|ф[аa][хҳ]мо(?:\s+шуд)?|ф[аa][хҳ]мидам'
        .'|хуб|хайр|саломат\s+бошед'
        // English
        .'|thanks(?:\s+a\s+lot)?|thank\s+you(?:\s+(?:so|very)\s+much)?|thx|ty|got\s+it|understood'
        .'|perfect|bye|goodbye|cheers'
        .')';

    /**
     * @var array<int, string>
     */
    protected const PATTERNS = [
        '/^'.self::SEPARATOR.'*(?:'.self::CLOSERS.'(?:'.self::SEPARATOR.'+|$))+$/u',
    ];
}
