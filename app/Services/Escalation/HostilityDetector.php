<?php

namespace App\Services\Escalation;

class HostilityDetector
{
    public const SIGNAL_HOSTILITY = 'frustration';

    public const SIGNAL_EXPLICIT = 'explicit_request';

    public const SIGNAL_REPEATED_FAILURE = 'fallback';

    protected const ABUSE_STEMS = [

        'бля', 'блят', 'бляд', 'хуй', 'хуе', 'хуё', 'хую', 'хуя', 'пизд', 'ебан', 'ебат', 'ебен',

        'блт', 'хй', 'пзд', 'бл',
        'ебё', 'ёбан', 'ёбну', 'заеб', 'заёб', 'уеб', 'уёб', 'долбоеб', 'долбоёб', 'мудак', 'мудил',
        'сука', 'суки', 'сучка', 'гандон', 'пидор', 'пидар', 'чмо', 'мраз', 'ублюд', 'тупорыл',
        'дебил', 'даун', 'идиот', 'кретин', 'придур', 'урод', 'скотин', 'тварь',

        'аҳмақ', 'ахмак', 'галтак', 'ебит',

        'fuck', 'fucking', 'shit', 'bitch', 'bastard', 'asshole', 'moron', 'retard', 'dumbass',
    ];

    protected const ABUSE_TOKENS = [
        'нах', 'нахуй', 'нахер', 'нахрен', 'нафиг', 'отвали', 'отстань', 'заткнись',
        'shut', 'stfu',
    ];

    protected const ABUSE_PHRASES = [
        'иди к черту', 'иди к чёрту', 'пошла ты', 'пошел ты', 'пошёл ты', 'да пошли вы',
        'иди ты', 'катись', 'достал', 'достала', 'задолбал', 'задолбала',
        'go to hell', 'get lost', 'piss off',
    ];

    protected const RUDE_STEMS = [
        'тупая', 'тупой', 'тупое', 'тупиц', 'глуп', 'бесполезн', 'бестолков', 'никчемн', 'никчёмн',
        'бесит', 'бесиш', 'раздража', 'достало', 'ужасн', 'отврат',
        'useless', 'stupid', 'idiotic', 'garbage', 'rubbish', 'worthless', 'pathetic',
    ];

    protected const ADDRESSED_AT_BOT = [
        'ты', 'тебе', 'тебя', 'тобой', 'вы', 'вас', 'вам', 'бот', 'робот', 'ассистент',
        'you', 'your', 'bot', 'robot', 'assistant', 'chatbot',
    ];

    protected const HUMAN_REQUEST_PHRASES = [
        'оператор', 'оператора', 'оператору', 'позовите', 'позови', 'соедините', 'соедини',
        'человека', 'человеком', 'с человеком', 'живого', 'живым', 'менеджер', 'менеджера',
        'администратор', 'администратора', 'начальник', 'руководств', 'владелец', 'хозяин',
        'не бот', 'не с ботом', 'настоящий человек', 'реальный человек',
        'operator', 'human', 'real person', 'manager', 'supervisor', 'agent please', 'talk to someone',
        'оператор даркор', 'одам', 'мудир',
    ];

    public function inspect(string $message, int $priorRudeMessages = 0): ?array
    {
        $text = $this->fold($message);

        if ($text === '') {
            return null;
        }

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

    protected function fold(string $message): string
    {
        $text = mb_strtolower(trim($message));

        $text = strtr($text, [
            'a' => 'а', 'b' => 'б', 'c' => 'с', 'e' => 'е', 'h' => 'н', 'k' => 'к', 'm' => 'м',
            'o' => 'о', 'p' => 'р', 't' => 'т', 'u' => 'у', 'x' => 'х', 'y' => 'у', 'z' => 'з',
            '0' => 'о', '3' => 'з', '4' => 'ч', '6' => 'б', '@' => 'а', '*' => '',
        ]);

        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        $text = (string) preg_replace('/(.)\1{2,}/u', '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    protected function folded(string $key, array $words): array
    {
        static $cache = [];

        return $cache[$key] ??= array_values(array_unique(array_map(
            fn (string $word) => $this->fold($word),
            $words,
        )));
    }

    protected function tokens(string $folded): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $folded) ?: []));
    }

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
