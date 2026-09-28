<?php

namespace App\Services\Reply;

class LanguageDetector
{
    public const RUSSIAN = 'ru';

    public const TAJIK = 'tj';

    public const ENGLISH = 'en';

    protected const TAJIK_LETTERS = ['ӣ', 'ӯ', 'ҷ', 'ҳ', 'қ', 'ғ'];

    protected const TAJIK_WORDS = [
        'салом', 'ассалом', 'ассалому', 'салому', 'алейкум', 'алайкум', 'ваалейкум',
        'раҳмат', 'рахмат', 'ташаккур', 'хайр', 'хуш', 'марҳамат', 'мархамат',
        'шумо', 'шумоён', 'ман', 'вай', 'онҳо', 'онхо', 'худам', 'худат', 'худаш',
        'чанд', 'чандта', 'чандто', 'кадом', 'кадоме', 'чихел', 'чиҳел', 'хел', 'ҳел',
        'кай', 'куҷо', 'кучо', 'куҷост', 'кучост', 'чаро', 'чиба', 'чист', 'чӣ',
        'ҳаст', 'хаст', 'ҳастед', 'хастед', 'ҳастам', 'хастам', 'нест', 'нестанд',
        'дорад', 'доред', 'дорем', 'дорам', 'доранд', 'доштан', 'дошта',
        'мебошад', 'мебошанд', 'мешавад', 'мешад', 'мешавед', 'мекунад', 'мекунед', 'мекунам',
        'мехоҳам', 'мехохам', 'мехоҳед', 'мехохед', 'мехоҳад', 'мехохад',
        'кунед', 'кунад', 'кунам', 'кардан', 'карда', 'кардед', 'гирифтан', 'гиред',
        'харидан', 'мехарам', 'диҳед', 'дихед', 'дода', 'бошад', 'бошед',
        'хай', 'хайми', 'ҳайми', 'дия', 'дигар', 'акнун', 'ҳоло', 'холо',
        'майлаш', 'майли', 'албатта', 'шояд', 'бале', 'наход',
        'нағз', 'нагз', 'хуб', 'хубтар', 'бад', 'калон', 'хурд', 'нав', 'кӯҳна', 'кухна',
        'арзон', 'арзонтар', 'қиммат', 'киммат', 'қимат', 'кимат',
        'нарх', 'нархи', 'нархаш', 'нархҳо', 'нархо', 'пул', 'сомонӣ',
        'молҳо', 'молхо', 'якта', 'дона', 'донааш', 'бисёр', 'бисьёр', 'бисер', 'каме',
        'рӯз', 'имрӯз', 'имруз', 'пагоҳ', 'пагох', 'соат', 'вақт', 'вакт',
        'дуруст', 'дурустар', 'ҳамин', 'хамин', 'ҳамааш', 'хамааш', 'ҳама',
        'барои', 'бароям', 'лозим', 'даркор', 'мумкин', 'мумкинми',
    ];

    protected const TAJIK_AMBIGUOUS_WORDS = [
        'ки', 'ва', 'дар', 'аз', 'ба', 'бо', 'ин', 'як', 'ду', 'се', 'чор', 'панҷ',
        'ҳам', 'хам', 'аст', 'буд', 'худ', 'лекин', 'аммо', 'бор', 'ном', 'номи',
        'ҳа', 'ха', 'чи', 'то', 'ё', 'руз', 'сомони',
    ];

    protected const TAJIK_PREFIXES = [
        'дуруст', 'мехо', 'мешав', 'мекун', 'доре', 'дора', 'намеш', 'наме',
        'ассалом', 'нарх', 'чандт', 'мебош', 'кадом', 'мумкин', 'раҳмат', 'рахмат',
    ];

    protected const TAJIK_SUFFIXES = ['атон', 'ятон'];

    protected const MIN_SUFFIX_TOKEN_LENGTH = 8;

    protected const TAJIK_LATIN_WORDS = [
        'salom', 'assalom', 'assalomu', 'alaykum', 'rahmat', 'rakhmat', 'khub', 'durust', 'durustar',
        'narkh', 'narh', 'somoni', 'shumo', 'mehoham', 'mekhoham', 'mехoham', 'chand', 'kucho', 'kujo',
        'chihel', 'chi hel', 'yakta', 'imruz', 'imrooz', 'pagoh', 'nest', 'hast', 'dored', 'doram',
        'majlash', 'maylash', 'albatta', 'bisyor', 'bisyer', 'kuned', 'kardan', 'haridan', 'kharidan',
    ];

    protected const RUSSIAN_WORDS = [
        'что', 'как', 'это', 'этот', 'вы', 'вас', 'вам', 'ваш', 'ваши', 'мне', 'меня', 'мы',
        'есть', 'нет', 'ли', 'не', 'на', 'для', 'или', 'но', 'если', 'уже', 'ещё', 'еще',
        'сколько', 'где', 'когда', 'почему', 'какой', 'какие', 'который',
        'привет', 'здравствуйте', 'спасибо', 'пожалуйста', 'извините', 'добрый',
        'можно', 'хочу', 'нужно', 'надо', 'купить', 'заказать', 'цена', 'цены', 'стоит',
        'доставка', 'наличии', 'магазин', 'товар', 'сомони', 'да', 'очень', 'тоже',
    ];

    // Определяет язык одного сообщения (тоҷикӣ / русский / английский)
    public function detect(string $message): ?string
    {
        $text = mb_strtolower(trim($message));

        if ($text === '') {
            return null;
        }

        foreach (self::TAJIK_LETTERS as $letter) {
            if (mb_strpos($text, $letter) !== false) {
                return self::TAJIK;
            }
        }

        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $text);
        $latin = preg_match_all('/[a-z]/u', $text);

        $tokens = $this->tokens($text);
        $russian = $this->countMatches($tokens, self::RUSSIAN_WORDS, []);

        if ($this->countMatches($tokens, self::TAJIK_WORDS, self::TAJIK_PREFIXES) > 0
            || $this->countSuffixMatches($tokens) > 0) {
            return self::TAJIK;
        }

        if ($russian === 0 && $this->countMatches($tokens, self::TAJIK_AMBIGUOUS_WORDS, []) >= 2) {
            return self::TAJIK;
        }

        if ($cyrillic > 0 && $russian === 0 && count($tokens) <= 2) {
            return null;
        }

        if ($cyrillic > 0) {
            return self::RUSSIAN;
        }

        if ($latin > 0) {
            return $this->countMatches($tokens, self::TAJIK_LATIN_WORDS, []) > 0
                ? self::TAJIK
                : self::ENGLISH;
        }

        return null;
    }

    // Определяет язык клиента по последним сообщениям диалога
    public function forConversation(iterable $recentMessages): ?string
    {
        foreach ($recentMessages as $message) {
            $language = $this->detect($message);

            if ($language !== null) {
                return $language;
            }
        }

        return null;
    }

    // Считает токены, оканчивающиеся на характерное таджикское окончание
    protected function countSuffixMatches(array $tokens): int
    {
        $matched = 0;

        foreach ($tokens as $token) {
            if (mb_strlen($token) < self::MIN_SUFFIX_TOKEN_LENGTH) {
                continue;
            }

            foreach (self::TAJIK_SUFFIXES as $suffix) {
                if (str_ends_with($token, $suffix)) {
                    $matched++;

                    break;
                }
            }
        }

        return $matched;
    }

    // Считает уникальные токены, совпавшие со словарём или его характерными префиксами
    protected function countMatches(array $tokens, array $words, array $prefixes): int
    {
        $matched = [];

        foreach ($tokens as $token) {
            if (in_array($token, $words, true)) {
                $matched[$token] = true;

                continue;
            }

            foreach ($prefixes as $prefix) {
                if (mb_strlen($token) > mb_strlen($prefix) && str_starts_with($token, $prefix)) {
                    $matched[$token] = true;

                    break;
                }
            }
        }

        return count($matched);
    }

    // Разбивает текст на слова-токены, отбрасывая пунктуацию
    protected function tokens(string $text): array
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: []));
    }
}
