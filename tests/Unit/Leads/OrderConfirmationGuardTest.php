<?php

namespace Tests\Unit\Leads;

use App\Services\Leads\OrderConfirmationGuard;
use PHPUnit\Framework\TestCase;

class OrderConfirmationGuardTest extends TestCase
{
    protected OrderConfirmationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new OrderConfirmationGuard;
    }

    /**
     * The four sentences the assistant actually sent, verbatim from the
     * transcript, none of which had an order behind it.
     */
    public function test_the_confirmations_from_the_reported_conversation_are_recognised(): void
    {
        foreach ([
            'Хуб, заявка қабул шуд! Мо бо шумо дар ин чат алоқа хоҳем гирифт.',
            'Хорошо, ваша заявка принята! Мы свяжемся с вами здесь, в чате.',
            'Не проблема! Мы все равно оформим вашу заявку без номера телефона.',
            'Ничего страшного! Я все равно зафиксирую ваш заказ.',
        ] as $reply) {
            $this->assertTrue(
                $this->guard->claimsOrderRecorded($reply),
                "[{$reply}] should read as an order confirmation",
            );
        }
    }

    /**
     * The expensive false positive: asking for details is the OPPOSITE of
     * confirming, and both sentences contain "оформить".
     */
    public function test_asking_for_details_is_not_a_confirmation(): void
    {
        foreach ([
            'Чтобы оформить заказ на MacBook Pro 14, пожалуйста, оставьте ваш номер телефона.',
            'Чтобы мы могли с вами связаться, оставьте, пожалуйста, номер телефона.',
            'Какой именно MacBook вас интересует?',
            'Самый мощный MacBook у нас — это MacBook Pro 14 с процессором Apple M5.',
            'Хотите оформить заказ?',
            '',
        ] as $reply) {
            $this->assertFalse(
                $this->guard->claimsOrderRecorded($reply),
                "[{$reply}] should not read as an order confirmation",
            );
        }
    }

    public function test_english_and_punctuation_variants_are_matched(): void
    {
        $this->assertTrue($this->guard->claimsOrderRecorded('Your order has been placed!'));
        $this->assertTrue($this->guard->claimsOrderRecorded('ЗАЯВКА, ПРИНЯТА...'));
        $this->assertFalse($this->guard->claimsOrderRecorded(null));
    }
}
