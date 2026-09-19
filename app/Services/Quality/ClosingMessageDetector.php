<?php

namespace App\Services\Quality;

use App\Services\Quality\Concerns\MatchesPatterns;

class ClosingMessageDetector
{
    use MatchesPatterns;

    protected const SEPARATOR = '[\s,.!?;:()\-—…\p{So}\p{Sk}\x{FE0F}\x{200D}]';

    protected const CLOSERS = '(?:'
        .'спасибо(?:\s+(?:большое|огромное))?|спс|благодарю|понятно|ясно|вс[её]\s+(?:понятно|ясно)'
        .'|отлично|до\s+свидания|всего\s+доброго|пока'
        .'|ра[хҳ]мат(?:\s+(?:калон|бисёр))?|ташакк?ур|сипос|мерси|ф[аa][хҳ]мо(?:\s+шуд)?|ф[аa][хҳ]мидам'
        .'|хуб|хайр|саломат\s+бошед'
        .'|thanks(?:\s+a\s+lot)?|thank\s+you(?:\s+(?:so|very)\s+much)?|thx|ty|got\s+it|understood'
        .'|perfect|bye|goodbye|cheers'
        .')';

    protected const PATTERNS = [
        '/^'.self::SEPARATOR.'*(?:'.self::CLOSERS.'(?:'.self::SEPARATOR.'+|$))+$/u',
    ];
}
