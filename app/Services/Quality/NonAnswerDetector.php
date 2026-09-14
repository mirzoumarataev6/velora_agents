<?php

namespace App\Services\Quality;

use App\Services\Quality\Concerns\MatchesPatterns;

/**
 * Did the agent tell the customer, in whatever words it chose, that it does not
 * know?
 *
 * Pattern-matched rather than compared against the configured fallback string,
 * which is what the detection used to rest on implicitly: the fallback only
 * appears when generation FAILED, so a model that wrote its own polite apology
 * — the overwhelmingly common case — matched nothing and the gap stayed
 * invisible. These are the phrasings the model actually reaches for, in the
 * three languages the product speaks.
 *
 * Kept deliberately to unambiguous "I don't know" constructions. A reply that
 * merely hedges ("возможно", "обычно") has still answered.
 *
 * A CLASS OF ITS OWN because two callers need exactly this judgement and must
 * never disagree about it: the live pipeline, which decides whether a turn goes
 * into the owner's "вопросы без ответа" list, and the backfill command that
 * re-judges turns already recorded under the older, wrong rule. A copy of these
 * patterns in the command would drift from the pipeline's the first time either
 * was edited, and the whole point of the backfill is to apply the SAME rule.
 */
class NonAnswerDetector
{
    use MatchesPatterns;

    /**
     * @var array<int, string>
     */
    protected const PATTERNS = [
        // Russian
        '/\bне зна(ю|ем)\b/u',
        '/\bне могу (ответить|сказать|подсказать|помочь)/u',
        '/\bне располага(ю|ем)\b/u',
        '/\bзатрудня(юсь|емся) ответить/u',
        '/\b(нет|не имею|не имеем)\s+(такой\s+)?(информации|данных|сведений)/u',
        '/\b(такой|этой|подобной)\s+информации\s+(у меня\s+)?нет/u',
        '/\bинформаци(и|я)\s+(об этом\s+)?(у меня\s+)?(нет|отсутствует)/u',
        // Scoped to what the AGENT has, deliberately. Bare "не указан" also
        // appears inside perfectly good answers that note a gap somewhere else
        // and then answer anyway — "точный адрес не указан на сайте, но мы в
        // Худжанде на Ленина 45" is an answer, and flagging it is the exact
        // failure this detector must not have.
        '/\bне\s+указан[аоы]?\b[^.!?]{0,40}\b(у меня|в моих|в базе|в материалах|в информации|в данных)/u',

        // Tajik
        '/\bнамедонам\b/u',
        '/\bмаълумот надорам\b/u',
        '/\bчунин маълумот нест\b/u',
        '/\bгуфта наметавонам\b/u',

        // English
        "/\bi (don't|do not|dont) know\b/u",
        "/\bi (don't|do not|dont) have (that|this|any) (information|detail|data)/u",
        '/\bno information (about|on|regarding)/u',
        '/\bi(\'m| am) (not sure|unable to (answer|say))/u',
        '/\b(can\'t|cannot|can not) (answer|help with) that/u',
    ];
}
