<?php

namespace Tests\Unit\Reply;

use App\Services\Reply\LanguageDetector;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    protected LanguageDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new LanguageDetector;
    }

    /**
     * The two reported messages. Neither contains a single Tajik-only letter —
     * they were typed on a Russian keyboard — which is exactly why the letters
     * alone were never going to be enough.
     */
    public function test_tajik_written_without_its_own_letters_is_still_tajik(): void
    {
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Дурустар кобед'));
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('20 бор якта диск шара т'));
    }

    public function test_tajik_letters_are_decisive_on_their_own(): void
    {
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Салом! Чӣ хел шумо?'));
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Нарх чанд сомонӣ?'));
    }

    /**
     * Code-mixing is the norm here: a Tajik sentence carrying Russian nouns.
     * It must not read as Russian just because the nouns are.
     */
    public function test_tajik_mixed_with_russian_words_resolves_to_tajik(): void
    {
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Ноутбук доред?'));
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Мехохам ноутбук харидан'));
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Ин диск чанд сомони?'));
    }

    /**
     * The reported message, plus the casual forms a customer actually types —
     * clipped, code-mixed, and spelled without the Tajik-only letters because
     * the keyboard is Russian.
     */
    public function test_real_casual_tajik_is_detected(): void
    {
        foreach ([
            // The reported message. "Шумо" carries it; the rest is why the
            // vocabulary had to grow — see the near-miss test below.
            'Ассалому алейкум Шумо ноутбуки игровоятон хайми',
            'Ассалому алейкум',
            'Салом, ноутбук доред?',
            'Нархаш чанд сомони',
            'Кадом ноутбук нағз?',
            'Хуб, майлаш',
            'Мумкин пагох гирам',
            'Ноутбукатон бисер кимат',
        ] as $message) {
            $this->assertSame(
                LanguageDetector::TAJIK,
                $this->detector->detect($message),
                "[{$message}] should read as Tajik",
            );
        }
    }

    /**
     * The near miss that exposed how narrow the vocabulary was: drop the one
     * pronoun from the reported message and nothing else in it registered, so it
     * came back Russian. Every word here is either a Russian loanword or a
     * hybrid form.
     */
    public function test_tajik_morphology_on_russian_loanwords_is_detected(): void
    {
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Ноутбуки игровоятон хайми'));
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Заказатон тайёр'));
        $this->assertSame(LanguageDetector::TAJIK, $this->detector->detect('Телефонатон чанд пул'));
    }

    /**
     * The collision check the expanded vocabulary has to survive.
     *
     * Two of these are regressions: the previous list held "ту", "мол", "он" and
     * "не" as Tajik words, so "Ту машину я уже видел" and "Мол, я не знал про
     * эту цену" both came back Tajik. The suffix rule adds its own risk, which
     * is why "батон" and "Платон" are here.
     */
    public function test_ordinary_russian_never_reads_as_tajik(): void
    {
        foreach ([
            'Есть ли у вас игровые ноуты',
            'Здравствуйте, сколько стоит доставка?',
            'Он не знает что это такое',
            'Это дар природы, а не товар',
            'Ту машину я уже видел',
            'Мол, я не знал про эту цену',
            'Купите батон и молоко',
            'Платон мне друг, но истина дороже',
            'Сколько стоит ноутбук для сына',
            'Дайте два ноутбука и один монитор',
            'Я хочу купить ноутбук в рассрочку',
            'Какой у вас адрес магазина',
        ] as $message) {
            $this->assertSame(
                LanguageDetector::RUSSIAN,
                $this->detector->detect($message),
                "[{$message}] should read as Russian",
            );
        }
    }

    public function test_russian_and_english_are_unchanged(): void
    {
        $this->assertSame(LanguageDetector::RUSSIAN, $this->detector->detect('Есть ли у вас игровые ноуты'));
        $this->assertSame(LanguageDetector::RUSSIAN, $this->detector->detect('Здравствуйте, сколько стоит доставка?'));
        $this->assertSame(LanguageDetector::ENGLISH, $this->detector->detect('Hello, do you have gaming laptops?'));
    }

    /**
     * The messages that must decide nothing. Calling a bare "ок" Russian is how
     * a Tajik conversation snaps back to Russian halfway through.
     */
    public function test_a_message_with_no_language_of_its_own_decides_nothing(): void
    {
        foreach (['ок', '👍', '5000', 'Ноутбук?', ''] as $message) {
            $this->assertNull($this->detector->detect($message), "[{$message}] should carry no signal");
        }
    }

    public function test_an_undetermined_message_inherits_from_the_last_determined_one(): void
    {
        // Newest first: "ок" carries nothing, so the Tajik before it stands.
        $this->assertSame(
            LanguageDetector::TAJIK,
            $this->detector->forConversation(['ок', 'Дурустар кобед', 'Здравствуйте']),
        );

        // And a real switch back is honoured immediately.
        $this->assertSame(
            LanguageDetector::RUSSIAN,
            $this->detector->forConversation(['Сколько это стоит?', 'Дурустар кобед']),
        );

        $this->assertNull($this->detector->forConversation(['ок', '👍']));
        $this->assertNull($this->detector->forConversation([]));
    }
}
