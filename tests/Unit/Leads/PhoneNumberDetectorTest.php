<?php

namespace Tests\Unit\Leads;

use App\Services\Leads\PhoneNumberDetector;
use PHPUnit\Framework\TestCase;

class PhoneNumberDetectorTest extends TestCase
{
    protected PhoneNumberDetector $phones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phones = new PhoneNumberDetector;
    }

    public function test_a_number_is_found_however_it_is_written(): void
    {
        $this->assertSame('+992901234567', $this->phones->find('Мой номер +992 90 123 45 67'));
        $this->assertSame('901234567', $this->phones->find('901234567'));
        $this->assertSame('927778899', $this->phones->find('звоните 92-777-88-99'));
        $this->assertSame('+79991234567', $this->phones->find('+7 (999) 123-45-67'));
        $this->assertSame('992918887766', $this->phones->find('мой ватсап 992918887766, звоните туда'));
    }

    /**
     * The expensive mistake. This catalog is full of four- and five-digit
     * numbers, and confirming an order with "мы вам позвоним" against a price
     * is worse than never having asked.
     */
    public function test_prices_and_quantities_are_not_phone_numbers(): void
    {
        foreach ([
            'OMEN 16 за 14999 сомони',
            'возьму 2 штуки по 8900',
            'заказ на 15 000 сомони',
            'Macbook Air 13.6 за 10499',
            'нет телефона, пишите сюда',
        ] as $text) {
            $this->assertNull($this->phones->find($text), "[{$text}] must not read as a phone number");
        }
    }

    public function test_the_first_number_across_several_messages_wins(): void
    {
        $this->assertSame('+992901234567', $this->phones->findIn([
            'ок, жду',
            'мой номер +992901234567',
            'сколько стоит?',
        ]));

        $this->assertNull($this->phones->findIn(['ок', 'не хочу оставлять номер', null]));
        $this->assertNull($this->phones->findIn([]));
    }
}
