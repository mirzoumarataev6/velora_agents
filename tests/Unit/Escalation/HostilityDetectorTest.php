<?php

namespace Tests\Unit\Escalation;

use App\Services\Escalation\HostilityDetector;
use PHPUnit\Framework\TestCase;

class HostilityDetectorTest extends TestCase
{
    protected HostilityDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new HostilityDetector;
    }

    /**
     * The four messages from the report, none of which produced a handover.
     */
    public function test_the_reported_hostile_messages_are_all_detected(): void
    {
        foreach (['Ебень ты тупорылая', 'Иди нах', 'Пошла ты к черту', 'Сука ты'] as $message) {
            $verdict = $this->detector->inspect($message);

            $this->assertNotNull($verdict, "[{$message}] should trigger a handover");
            $this->assertSame(HostilityDetector::SIGNAL_HOSTILITY, $verdict['signal']);
            $this->assertSame('abuse', $verdict['reason']);
        }
    }

    /**
     * Abuse is typed on whatever keyboard is to hand, and censored on purpose.
     * A detector that only reads clean Cyrillic misses exactly the messages
     * someone took the trouble to disguise.
     */
    public function test_obfuscated_and_transliterated_abuse_is_detected(): void
    {
        foreach (['cyka', 'бл*ть', 'сууукааа', 'ПИZДЕЦ ваш бот', 'fuck this bot'] as $message) {
            $this->assertTrue($this->detector->isAbusive($message), "[{$message}] should read as abuse");
        }
    }

    /**
     * The expensive mistake in the other direction: ordinary words that contain
     * an abusive stem. A substring match fires on every one of these.
     */
    public function test_ordinary_messages_are_not_mistaken_for_abuse(): void
    {
        foreach ([
            'У меня ребенок школьник, нужен ноутбук',
            'Где вы находитесь?',
            'Сколько стоит доставка?',
            'Этот нож тупой, можно вернуть?',
            'Находится ли магазин в центре?',
            'Есть ноутбуки до 15000?',
        ] as $message) {
            $this->assertFalse($this->detector->isAbusive($message), "[{$message}] should not read as abuse");
            $this->assertNull($this->detector->inspect($message), "[{$message}] should not trigger a handover");
        }
    }

    public function test_asking_for_a_person_is_an_explicit_request(): void
    {
        foreach ([
            'позовите оператора',
            'Хочу говорить с человеком',
            'соедините с менеджером',
            'is there a real person i can talk to',
        ] as $message) {
            $verdict = $this->detector->inspect($message);

            $this->assertNotNull($verdict, "[{$message}] should trigger a handover");
            $this->assertSame(HostilityDetector::SIGNAL_EXPLICIT, $verdict['signal']);
        }
    }

    /**
     * Rudeness aimed at the assistant is a bad moment once and a failing
     * conversation twice.
     */
    public function test_rudeness_escalates_on_the_second_message_not_the_first(): void
    {
        $this->assertNull($this->detector->inspect('ты бесполезный бот', 0));

        $verdict = $this->detector->inspect('ты бесполезный бот', 1);

        $this->assertNotNull($verdict);
        $this->assertSame('repeated_rudeness', $verdict['reason']);
    }

    public function test_rudeness_about_a_product_is_not_rudeness_about_the_assistant(): void
    {
        // No "ты"/"вы"/"бот" anywhere: this is a complaint about a knife.
        $this->assertFalse($this->detector->isRude('нож тупой и бесполезный'));
        $this->assertTrue($this->detector->isRude('ты бесполезный'));
    }
}
