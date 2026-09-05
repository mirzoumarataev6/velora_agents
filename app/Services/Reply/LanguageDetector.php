<?php

namespace App\Services\Reply;

/**
 * Which language a customer is writing in, so the reply can be composed in it.
 *
 * WHY THIS IS NOT LEFT TO THE MODEL. The prompt already said "reply in the same
 * language the customer used", and for Russian and English that is enough. It is
 * not enough for Tajik, because Tajik is written in Cyrillic and a short Tajik
 * message looks, to a model weighing probabilities over a mostly-Russian
 * conversation, like slightly odd Russian. "Дурустар кобед" got answered in
 * Russian. Naming the language explicitly in the prompt removes the guess.
 *
 * TAJIK IS THE HARD CASE, AND IT IS THE ONE THAT MATTERS. The obvious signal —
 * the letters Tajik has and Russian does not (ӣ ӯ ҷ ҳ қ ғ) — is decisive when
 * present and frequently absent: people type on a Russian keyboard and drop the
 * diacritics. So the second signal is vocabulary, matched as whole words, which
 * is what catches "20 бор якта диск шара т".
 *
 * CODE-MIXING RESOLVES TO TAJIK. Tajik speakers mix Russian nouns into Tajik
 * sentences constantly ("диск", "заказ", "магазин"), so a message containing
 * both is far more likely to be Tajik with borrowings than Russian with stray
 * Tajik grammar. Ties therefore go to Tajik. The reverse error — answering a
 * Russian speaker in Tajik — is guarded by requiring a real Tajik word to
 * appear at all: Russian text scores zero here, not one.
 *
 * This never touches the knowledge base or the catalog. It decides one thing:
 * what language the assistant writes its next message in.
 */
class LanguageDetector
{
    /** Agent-language codes, matching AgentReplyService::LANGUAGE_NAMES. */
    public const RUSSIAN = 'ru';

    public const TAJIK = 'tj';

    public const ENGLISH = 'en';

    /**
     * Letters that exist in Tajik and not in Russian. One is proof.
     */
    protected const TAJIK_LETTERS = ['ӣ', 'ӯ', 'ҷ', 'ҳ', 'қ', 'ғ'];

    /**
     * Everyday Tajik words that are NOT also Russian words. One is enough.
     *
     * Whole tokens rather than substrings: most are two or three letters and
     * would match inside Russian words constantly ("ба" in "работа", "ин" in
     * "магазин").
     *
     * @var string[]
     */
    protected const TAJIK_WORDS = [
        // Greetings and courtesies — very often the entire first message.
        'салом', 'ассалом', 'ассалому', 'салому', 'алейкум', 'алайкум', 'ваалейкум',
        'раҳмат', 'рахмат', 'ташаккур', 'хайр', 'хуш', 'марҳамат', 'мархамат',
        // Pronouns. "ту", "мо" and "он" are deliberately absent — see the note
        // on AMBIGUOUS_WORDS below.
        'шумо', 'шумоён', 'ман', 'вай', 'онҳо', 'онхо', 'худам', 'худат', 'худаш',
        // Question words.
        'чанд', 'чандта', 'чандто', 'кадом', 'кадоме', 'чихел', 'чиҳел', 'хел', 'ҳел',
        'кай', 'куҷо', 'кучо', 'куҷост', 'кучост', 'чаро', 'чиба', 'чист', 'чӣ',
        // Being, having, wanting — the spine of a customer's message.
        'ҳаст', 'хаст', 'ҳастед', 'хастед', 'ҳастам', 'хастам', 'нест', 'нестанд',
        'дорад', 'доред', 'дорем', 'дорам', 'доранд', 'доштан', 'дошта',
        'мебошад', 'мебошанд', 'мешавад', 'мешад', 'мешавед', 'мекунад', 'мекунед', 'мекунам',
        'мехоҳам', 'мехохам', 'мехоҳед', 'мехохед', 'мехоҳад', 'мехохад',
        'кунед', 'кунад', 'кунам', 'кардан', 'карда', 'кардед', 'гирифтан', 'гиред',
        'харидан', 'мехарам', 'диҳед', 'дихед', 'дода', 'бошад', 'бошед',
        // Casual particles and interjections, as they are actually typed.
        'хай', 'хайми', 'ҳайми', 'дия', 'дигар', 'акнун', 'ҳоло', 'холо',
        'майлаш', 'майли', 'албатта', 'шояд', 'бале', 'наход',
        // Adjectives and the nouns of a shop conversation.
        'нағз', 'нагз', 'хуб', 'хубтар', 'бад', 'калон', 'хурд', 'нав', 'кӯҳна', 'кухна',
        'арзон', 'арзонтар', 'қиммат', 'киммат', 'қимат', 'кимат',
        'нарх', 'нархи', 'нархаш', 'нархҳо', 'нархо', 'пул', 'сомонӣ',
        'молҳо', 'молхо', 'якта', 'дона', 'донааш', 'бисёр', 'бисьёр', 'бисер', 'каме',
        'рӯз', 'имрӯз', 'имруз', 'пагоҳ', 'пагох', 'соат', 'вақт', 'вакт',
        'дуруст', 'дурустар', 'ҳамин', 'хамин', 'ҳамааш', 'хамааш', 'ҳама',
        'барои', 'бароям', 'лозим', 'даркор', 'мумкин', 'мумкинми',
    ];

    /**
     * Tajik words that are ALSO ordinary Russian words, or plausible Russian
     * tokens. Two of them are needed before they mean anything.
     *
     * The tier exists because these are among the commonest words in the
     * language, and dropping them outright would blind the detector to whole
     * sentences — but counting any one of them alone turns "Это дар природы"
     * into Tajik.
     *
     * DELIBERATELY NOT LISTED AT ALL: "не", "он", "ту", "мо", "мол". Each is
     * genuinely Tajik, and each is so common in Russian that even the two-hit
     * rule breaks on it — "он не знает" scores two and flips a plainly Russian
     * sentence. A Tajik sentence using them almost always contains something
     * from the strong list as well, so what is lost is small and what it
     * prevents is not. This is why the previous vocabulary misfired on "Ту
     * машину я уже видел" and "Мол, я не знал про эту цену".
     *
     * @var string[]
     */
    protected const TAJIK_AMBIGUOUS_WORDS = [
        'ки', 'ва', 'дар', 'аз', 'ба', 'бо', 'ин', 'як', 'ду', 'се', 'чор', 'панҷ',
        'ҳам', 'хам', 'аст', 'буд', 'худ', 'лекин', 'аммо', 'бор', 'ном', 'номи',
        'ҳа', 'ха', 'чи', 'то', 'ё', 'руз', 'сомони',
    ];

    /**
     * Tajik roots regular enough to match by prefix. Kept short deliberately —
     * each one is a licence for a false positive.
     *
     * @var string[]
     */
    protected const TAJIK_PREFIXES = [
        'дуруст', 'мехо', 'мешав', 'мекун', 'доре', 'дора', 'намеш', 'наме',
        'ассалом', 'нарх', 'чандт', 'мебош', 'кадом', 'мумкин', 'раҳмат', 'рахмат',
    ];

    /**
     * Possessive endings attached to a borrowed stem: "ноутбукатон" (your
     * laptop), "игровоятон" (your gaming one), "заказатон".
     *
     * WHY ONLY THESE TWO, AND WHY A LENGTH FLOOR. Code-mixed Tajik puts Tajik
     * morphology on Russian stems constantly, and the hybrid is a strong signal
     * precisely because Russian has no such form. But most Tajik suffixes are
     * unusable for this: "-ро" ends метро, ведро, быстро, добро; "-ам"/"-ат"
     * end салат, халат, брат; "-мон" ends лимон, демон, гормон; "-тон" ends
     * картон, бетон, жетон. Matching any of them would fire on ordinary Russian
     * constantly, which is the one thing this detector must not do.
     *
     * "-атон"/"-ятон" pass the same test with one qualification: "батон" and
     * "Платон" are real Russian words ending in -атон. Both are short, while the
     * hybrid forms are long — the suffix rides on a whole borrowed noun — so a
     * minimum token length separates them cleanly. No Russian word of eight
     * letters or more ends this way.
     *
     * @var string[]
     */
    protected const TAJIK_SUFFIXES = ['атон', 'ятон'];

    /** Shortest token the suffix rule will look at. This is what keeps "батон" out. */
    protected const MIN_SUFFIX_TOKEN_LENGTH = 8;

    /**
     * Tajik written in the latin alphabet — "salom", "rahmat", "narkh chand".
     *
     * A separate list rather than transliterating the Cyrillic one, because the
     * risk here is entirely different: the competition is English, and Tajik's
     * commonest short words collide with it head-on ("in", "on", "man", "us",
     * "dar"). So this holds only tokens that are unmistakably not English, and
     * the short grammatical words are deliberately left out.
     *
     * @var string[]
     */
    protected const TAJIK_LATIN_WORDS = [
        'salom', 'assalom', 'assalomu', 'alaykum', 'rahmat', 'rakhmat', 'khub', 'durust', 'durustar',
        'narkh', 'narh', 'somoni', 'shumo', 'mehoham', 'mekhoham', 'mехoham', 'chand', 'kucho', 'kujo',
        'chihel', 'chi hel', 'yakta', 'imruz', 'imrooz', 'pagoh', 'nest', 'hast', 'dored', 'doram',
        'majlash', 'maylash', 'albatta', 'bisyor', 'bisyer', 'kuned', 'kardan', 'haridan', 'kharidan',
    ];

    /**
     * Everyday Russian words. Used only to weigh a mixed message; Russian is
     * also the fallback, so this list does not need to be exhaustive.
     *
     * @var string[]
     */
    protected const RUSSIAN_WORDS = [
        'что', 'как', 'это', 'этот', 'вы', 'вас', 'вам', 'ваш', 'ваши', 'мне', 'меня', 'мы',
        'есть', 'нет', 'ли', 'не', 'на', 'для', 'или', 'но', 'если', 'уже', 'ещё', 'еще',
        'сколько', 'где', 'когда', 'почему', 'какой', 'какие', 'который',
        'привет', 'здравствуйте', 'спасибо', 'пожалуйста', 'извините', 'добрый',
        'можно', 'хочу', 'нужно', 'надо', 'купить', 'заказать', 'цена', 'цены', 'стоит',
        'доставка', 'наличии', 'магазин', 'товар', 'сомони', 'да', 'очень', 'тоже',
    ];

    /**
     * The language of one message, or null when it carries no signal at all
     * (an emoji, a number, a bare "ok").
     */
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

        // One unambiguous Tajik word decides it, however much Russian surrounds
        // it. That is the code-mixing case, and here it is the common one: a
        // Tajik sentence carrying Russian nouns ("ноутбуки игровоятон хайми") is
        // far likelier than Russian carrying Tajik grammar. It is only safe
        // because the strong list holds nothing that is also a Russian word.
        if ($this->countMatches($tokens, self::TAJIK_WORDS, self::TAJIK_PREFIXES) > 0
            || $this->countSuffixMatches($tokens) > 0) {
            return self::TAJIK;
        }

        // Words that are Tajik AND Russian count only in company, and only when
        // nothing Russian is arguing back.
        if ($russian === 0 && $this->countMatches($tokens, self::TAJIK_AMBIGUOUS_WORDS, []) >= 2) {
            return self::TAJIK;
        }

        // A word or two of Cyrillic that matches neither vocabulary — "ок",
        // "Ноутбук?", a brand name — is not evidence of anything. Calling it
        // Russian is how a Tajik conversation snaps back to Russian on the first
        // one-word message; returning null lets it inherit what came before.
        if ($cyrillic > 0 && $russian === 0 && count($tokens) <= 2) {
            return null;
        }

        if ($cyrillic > 0) {
            return self::RUSSIAN;
        }

        // Latin with no Cyrillic anywhere. Tajik typed on a latin keyboard is
        // common enough here to check for by name; anything else is English,
        // which is the recoverable mistake to make.
        if ($latin > 0) {
            return $this->countMatches($tokens, self::TAJIK_LATIN_WORDS, []) > 0
                ? self::TAJIK
                : self::ENGLISH;
        }

        return null;
    }

    /**
     * The language to reply in, given the customer's messages newest first.
     *
     * A short message often carries no signal of its own — "ок", "👍", "5000" —
     * and switching language on it, or defaulting to Russian, is how a Tajik
     * conversation snaps back to Russian halfway through. So an undetermined
     * message inherits from the most recent one that was determined, and the
     * language only changes when the customer actually writes in another.
     *
     * @param  iterable<string>  $recentMessages  Newest first.
     */
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

    /**
     * Tokens carrying a Tajik possessive ending on a borrowed stem.
     *
     * @param  string[]  $tokens
     */
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

    /**
     * @param  string[]  $tokens
     * @param  string[]  $words
     * @param  string[]  $prefixes
     */
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

        // Distinct words, so one word repeated five times is one signal.
        return count($matched);
    }

    /**
     * @return string[]
     */
    protected function tokens(string $text): array
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: []));
    }
}
