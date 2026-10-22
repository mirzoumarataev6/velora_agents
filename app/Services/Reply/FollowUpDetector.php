<?php

namespace App\Services\Reply;

class FollowUpDetector
{
    protected const MAX_LENGTH = 40;

    protected const MAX_WORDS = 6;

    protected const REFERENTIAL = [
        'да', 'нет', 'ага', 'угу', 'ок', 'окей', 'хорошо', 'ладно', 'понятно', 'понял', 'поняла',
        'так да или нет', 'да или нет', 'ну так', 'и', 'ну и', 'так', 'ну',
        'yes', 'no', 'ok', 'okay', 'sure', 'right', 'yes or no', 'so yes or no',
        'ҳа', 'ха', 'не', 'хуб', 'майлаш',
        'это все', 'это всё', 'и все', 'и всё', 'всё', 'все', 'больше нет', 'это всё что есть',
        'что еще', 'что ещё', 'еще', 'ещё', 'дальше', 'а еще', 'а ещё',
        'is that all', 'that all', 'anything else', 'more',
        'ҳамин', 'хамин', 'ҳамааш', 'хамааш',
        'первый', 'второй', 'третий', 'последний', 'этот', 'тот', 'первое', 'второе',
        'first', 'second', 'third', 'the first one', 'this one', 'that one',
        'а точнее', 'точнее', 'конкретнее', 'а именно', 'например', 'почему', 'зачем', 'а что',
        'why', 'why not', 'how so', 'which one',
    ];

    protected const REFERENTIAL_PREFIXES = [
        'а ', 'и ', 'ну ', 'так ', 'значит ', 'то есть ',
        'and ', 'so ', 'but ', 'then ',
    ];

    public function requestsRecap(string $message): bool
    {
        $text = $this->fold($message);

        return preg_match('/^(?:(?:пожалуйста|please|лутфан)\s+)?(?:'
            .'(?:повтори(?:те)?|напомни(?:те)?)(?:\s|$)'
            .'|(?:можешь|можете)\s+(?:повторить|напомнить)(?:\s|$)'
            .'|(?:repeat|remind me)(?:\s|$)'
            .'|(?:can|could) you\s+(?:please\s+)?(?:repeat|remind)(?:\s|$)'
            .'|такрор\s+(?:кун|кунед)(?:\s|$)'
            .'|бори дигар\s+(?:гӯй|гӯед|гуй|гуед)(?:\s|$)'
            .')/u', $text) === 1;
    }

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

    protected function fold(string $message): string
    {
        $text = mb_strtolower(trim($message));
        $text = (string) preg_replace('/[!?.,;:…"«»\-—]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
