<?php

namespace Tests\Unit\Reply;

use App\Services\Reply\FollowUpDetector;
use PHPUnit\Framework\TestCase;

class FollowUpDetectorTest extends TestCase
{
    protected FollowUpDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new FollowUpDetector;
    }

    /**
     * The message from the bug report, and its neighbours.
     */
    public function test_a_short_referential_message_is_a_follow_up(): void
    {
        foreach ([
            'Так да или нет',
            'Так да или нет?',
            'да',
            'Нет.',
            'это все?',
            'Это всё?',
            'а подешевле?',
            'и сколько?',
            'первый',
            'yes or no',
        ] as $message) {
            $this->assertTrue(
                $this->detector->isShortFollowUp($message, true),
                "[{$message}] should read as a follow-up",
            );
        }
    }

    public function test_a_message_with_a_subject_of_its_own_is_not_a_follow_up(): void
    {
        foreach ([
            'Есть игровые ноутбуки до 15000?',
            'Сколько стоит доставка в Худжанд?',
            'А есть такие же, но с доставкой на завтра утром?',
            'Хочу купить ноутбук для сына, он школьник',
        ] as $message) {
            $this->assertFalse(
                $this->detector->isShortFollowUp($message, true),
                "[{$message}] should not read as a follow-up",
            );
        }
    }

    /**
     * The first message of a conversation cannot refer back to anything — "да"
     * as an opener is someone answering a greeting.
     */
    public function test_nothing_is_a_follow_up_before_the_assistant_has_replied(): void
    {
        $this->assertFalse($this->detector->isShortFollowUp('да', false));
        $this->assertFalse($this->detector->isShortFollowUp('так да или нет', false));
    }

    public function test_explicit_recap_is_distinguished_from_complaints_about_repetition(): void
    {
        foreach (['Повтори, пожалуйста, цены', 'Можете напомнить адрес?', 'Please repeat the options',
            'Could you please remind me of the price?', 'Лутфан такрор кунед', 'Бори дигар гӯед'] as $text) {
            $this->assertTrue($this->detector->requestsRecap($text), $text);
        }
        foreach (['Не повторяй одно и то же', 'Почему ты повторяешь?', "Don't repeat yourself", 'А подешевле?',
            'Такрор накунед', 'Do you have another model?'] as $text) {
            $this->assertFalse($this->detector->requestsRecap($text), $text);
        }
    }
}
