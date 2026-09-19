<?php

namespace App\Services\Quality;

use App\Services\Quality\Concerns\MatchesPatterns;

class NonAnswerDetector
{
    use MatchesPatterns;

    protected const PATTERNS = [
        '/\bне зна(ю|ем)\b/u',
        '/\bне могу (ответить|сказать|подсказать|помочь)/u',
        '/\bне располага(ю|ем)\b/u',
        '/\bзатрудня(юсь|емся) ответить/u',
        '/\b(нет|не имею|не имеем)\s+(такой\s+)?(информации|данных|сведений)/u',
        '/\b(такой|этой|подобной)\s+информации\s+(у меня\s+)?нет/u',
        '/\bинформаци(и|я)\s+(об этом\s+)?(у меня\s+)?(нет|отсутствует)/u',
        '/\bне\s+указан[аоы]?\b[^.!?]{0,40}\b(у меня|в моих|в базе|в материалах|в информации|в данных)/u',

        '/\bнамедонам\b/u',
        '/\bмаълумот надорам\b/u',
        '/\bчунин маълумот нест\b/u',
        '/\bгуфта наметавонам\b/u',

        "/\bi (don't|do not|dont) know\b/u",
        "/\bi (don't|do not|dont) have (that|this|any) (information|detail|data)/u",
        '/\bno information (about|on|regarding)/u',
        '/\bi(\'m| am) (not sure|unable to (answer|say))/u',
        '/\b(can\'t|cannot|can not) (answer|help with) that/u',
    ];
}
