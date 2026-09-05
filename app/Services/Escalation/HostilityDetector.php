<?php

namespace App\Services\Escalation;

/**
 * Deterministic detection of a customer who should be in front of a person:
 * abuse aimed at the assistant, an explicit request for a human, or a question
 * the assistant has now failed twice.
 *
 * WHY THIS EXISTS ALONGSIDE THE MODEL'S OWN TOOL. Escalation used to rest
 * entirely on the model calling flag_needs_human and self-reporting confidence
 * above the sensitivity bar. That works for the calm cases and fails for the one
 * that matters most: a customer swearing at the bot. A model whose whole prompt
 * tells it to be warm and de-escalating reads insults as something to apologise
 * for, keeps apologising, and never raises the flag — which is exactly what was
 * reported, four hostile messages deep with no handover.
 *
 * So the model's judgement is kept as one input and is no longer the only one.
 * Abuse is a fact about the text, not a judgement call, and a fact can be
 * checked without asking anyone's opinion.
 *
 * FALSE POSITIVES ARE THE CHEAP DIRECTION HERE, and that is the opposite of the
 * tool's calibration — on purpose. A wrongly flagged conversation costs the
 * owner one glance at their inbox; a missed one costs the customer. But "cheap"
 * is not "free", so the vocabulary is split in two: unambiguous abuse fires on
 * sight, while words that are merely rude ("тупой", "бесполезный") have to
 * either be aimed at the assistant or happen twice.
 */
class HostilityDetector
{
    /** What was detected. Maps onto EscalationService::SIGNALS. */
    public const SIGNAL_HOSTILITY = 'frustration';

    public const SIGNAL_EXPLICIT = 'explicit_request';

    public const SIGNAL_REPEATED_FAILURE = 'fallback';

    /**
     * Profanity and abuse that needs no context. Matched as a prefix of a WORD,
     * never as a bare substring: "ебен" inside "ребенок" and "нах" inside
     * "находится" are the two false positives a substring match guarantees.
     *
     * Stems rather than words, because these inflect freely and the customer is
     * not being careful: "ебень", "ебанутая", "ебаный" are one entry.
     *
     * @var string[]
     */
    protected const ABUSE_STEMS = [
        // Russian profanity, by root.
        'бля', 'блят', 'бляд', 'хуй', 'хуе', 'хуё', 'хую', 'хуя', 'пизд', 'ебан', 'ебат', 'ебен',
        // Censored spellings, once the asterisks are gone: "бл*ть" folds to
        // "блть", "х*й" to "хй". No Russian word begins with these clusters, so
        // matching them costs nothing and catches the messages someone
        // deliberately disguised.
        'блт', 'хй', 'пзд', 'бл',
        'ебё', 'ёбан', 'ёбну', 'заеб', 'заёб', 'уеб', 'уёб', 'долбоеб', 'долбоёб', 'мудак', 'мудил',
        'сука', 'суки', 'сучка', 'гандон', 'пидор', 'пидар', 'чмо', 'мраз', 'ублюд', 'тупорыл',
        'дебил', 'даун', 'идиот', 'кретин', 'придур', 'урод', 'скотин', 'тварь',
        // Tajik / Uzbek.
        'аҳмақ', 'ахмак', 'галтак', 'ебит',
        // English.
        'fuck', 'fucking', 'shit', 'bitch', 'bastard', 'asshole', 'moron', 'retard', 'dumbass',
    ];

    /**
     * Whole tokens only. Each of these is a prefix of an ordinary word, so a
     * prefix rule would fire on "находится", "нести", "поход".
     *
     * @var string[]
     */
    protected const ABUSE_TOKENS = [
        'нах', 'нахуй', 'нахер', 'нахрен', 'нафиг', 'отвали', 'отстань', 'заткнись',
        'shut', 'stfu',
    ];

    /**
     * Phrases whose hostility is in the combination. Matched anywhere in the
     * folded message.
     *
     * @var string[]
     */
    protected const ABUSE_PHRASES = [
        'иди к черту', 'иди к чёрту', 'пошла ты', 'пошел ты', 'пошёл ты', 'да пошли вы',
        'иди ты', 'катись', 'достал', 'достала', 'задолбал', 'задолбала',
        'go to hell', 'get lost', 'piss off',
    ];

    /**
     * Rude about the assistant, but not abuse. Fires only when it is aimed at
     * the assistant (a "ты"/"вы"/"бот"/"you" nearby) or when it is the second
     * such message in the conversation — "тупой нож" is a product complaint and
     * "ты тупой" is not.
     *
     * @var string[]
     */
    protected const RUDE_STEMS = [
        'тупая', 'тупой', 'тупое', 'тупиц', 'глуп', 'бесполезн', 'бестолков', 'никчемн', 'никчёмн',
        'бесит', 'бесиш', 'раздража', 'достало', 'ужасн', 'отврат',
        'useless', 'stupid', 'idiotic', 'garbage', 'rubbish', 'worthless', 'pathetic',
    ];

    /**
     * Words that make a rude message ABOUT THE ASSISTANT rather than about a
     * product or the weather.
     *
     * @var string[]
     */
    protected const ADDRESSED_AT_BOT = [
        'ты', 'тебе', 'тебя', 'тобой', 'вы', 'вас', 'вам', 'бот', 'робот', 'ассистент',
        'you', 'your', 'bot', 'robot', 'assistant', 'chatbot',
    ];

    /**
     * A stated request for a person. Deliberately generous — this is the case
     * where a customer has already given up on the assistant, and there is no
     * cost to believing them.
     *
     * @var string[]
     */
    protected const HUMAN_REQUEST_PHRASES = [
        'оператор', 'оператора', 'оператору', 'позовите', 'позови', 'соедините', 'соедини',
        'человека', 'человеком', 'с человеком', 'живого', 'живым', 'менеджер', 'менеджера',
        'администратор', 'администратора', 'начальник', 'руководств', 'владелец', 'хозяин',
        'не бот', 'не с ботом', 'настоящий человек', 'реальный человек',
        'operator', 'human', 'real person', 'manager', 'supervisor', 'agent please', 'talk to someone',
        'оператор даркор', 'одам', 'мудир',
    ];

    /**
     * What the customer's latest message warrants, or null when nothing.
     *
     * $priorRudeMessages is how many earlier customer messages in this
     * conversation were already rude — it is what lets a single "бесполезный"
     * pass and a second one escalate.
     *
     * @return array{signal: string, reason: string}|null
     */
    public function inspect(string $message, int $priorRudeMessages = 0): ?array
    {
        $text = $this->fold($message);

        if ($text === '') {
            return null;
        }

        // Checked before abuse: "позовите живого человека, задолбал ваш бот" is
        // both, and the request is the more actionable of the two — it says what
        // the customer actually wants.
        if ($this->matchesPhrase($text, self::HUMAN_REQUEST_PHRASES)) {
            return ['signal' => self::SIGNAL_EXPLICIT, 'reason' => 'asked_for_human'];
        }

        if ($this->isAbusive($text)) {
            return ['signal' => self::SIGNAL_HOSTILITY, 'reason' => 'abuse'];
        }

        if ($this->isRude($text)) {
            return $priorRudeMessages > 0
                ? ['signal' => self::SIGNAL_HOSTILITY, 'reason' => 'repeated_rudeness']
                : null;
        }

        return null;
    }

    /** Unambiguous abuse: profanity, a dismissal, or a "go away" phrase. */
    public function isAbusive(string $message): bool
    {
        $text = $this->fold($message);
        $tokens = $this->tokens($text);

        foreach ($tokens as $token) {
            if (in_array($token, $this->folded('abuse_tokens', self::ABUSE_TOKENS), true)) {
                return true;
            }

            foreach ($this->folded('abuse_stems', self::ABUSE_STEMS) as $stem) {
                if ($stem !== '' && str_starts_with($token, $stem)) {
                    return true;
                }
            }
        }

        return $this->matchesPhrase($text, self::ABUSE_PHRASES);
    }

    /** Rude about the assistant without being abuse. */
    public function isRude(string $message): bool
    {
        $text = $this->fold($message);
        $tokens = $this->tokens($text);

        $rude = false;

        foreach ($tokens as $token) {
            foreach ($this->folded('rude_stems', self::RUDE_STEMS) as $stem) {
                if ($stem !== '' && str_starts_with($token, $stem)) {
                    $rude = true;
                    break 2;
                }
            }
        }

        if (! $rude) {
            return false;
        }

        return array_intersect($tokens, $this->folded('addressed', self::ADDRESSED_AT_BOT)) !== [];
    }

    public function asksForHuman(string $message): bool
    {
        return $this->matchesPhrase($this->fold($message), self::HUMAN_REQUEST_PHRASES);
    }

    /**
     * Fold a message for comparison: lowercase, latin lookalikes folded back to
     * Cyrillic, repeated letters collapsed, punctuation dropped.
     *
     * The lookalike step is not decoration. Abuse is typed on whatever keyboard
     * is to hand and censored on purpose — "cyka", "cу4ка", "бл*ть" — and a
     * detector that only reads clean Cyrillic misses precisely the messages
     * someone took the trouble to disguise.
     */
    protected function fold(string $message): string
    {
        $text = mb_strtolower(trim($message));

        // Latin and digit lookalikes to their Cyrillic counterparts.
        $text = strtr($text, [
            'a' => 'а', 'b' => 'б', 'c' => 'с', 'e' => 'е', 'h' => 'н', 'k' => 'к', 'm' => 'м',
            'o' => 'о', 'p' => 'р', 't' => 'т', 'u' => 'у', 'x' => 'х', 'y' => 'у', 'z' => 'з',
            '0' => 'о', '3' => 'з', '4' => 'ч', '6' => 'б', '@' => 'а', '*' => '',
        ]);

        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        // "сууукаааа" is one word, said loudly.
        $text = (string) preg_replace('/(.)\1{2,}/u', '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A vocabulary list folded the way a message is, computed once.
     *
     * Both sides must go through the same folding or they can never meet: fold()
     * rewrites latin lookalikes into Cyrillic, so an unfolded "fuck" is compared
     * against a token that has already become "fуск".
     *
     * @param  string[]  $words
     * @return string[]
     */
    protected function folded(string $key, array $words): array
    {
        static $cache = [];

        return $cache[$key] ??= array_values(array_unique(array_map(
            fn (string $word) => $this->fold($word),
            $words,
        )));
    }

    /**
     * @return string[]
     */
    protected function tokens(string $folded): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $folded) ?: []));
    }

    /**
     * @param  string[]  $phrases
     */
    protected function matchesPhrase(string $folded, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($folded, $this->fold($phrase))) {
                return true;
            }
        }

        return false;
    }
}
