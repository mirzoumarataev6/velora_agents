<?php

namespace Tests\Unit\Reply;

use App\Services\Reply\ReplyStyleCleaner;
use PHPUnit\Framework\TestCase;

class ReplyStyleCleanerTest extends TestCase
{
    public function test_generic_invitations_are_removed_while_the_answer_survives(): void
    {
        $cleaner = new ReplyStyleCleaner;
        foreach ([
            ['Доставка стоит 50 сомони. Как вы хотите поступить?', 'Доставка стоит 50 сомони.'],
            ['Lenovo стоит 5900 сомони. Хотите узнать подробнее или сразу оформить заказ?', 'Lenovo стоит 5900 сомони.'],
            ['Пожалуйста! Если у вас возникнут вопросы, пишите.', 'Пожалуйста!'],
            ['Lenovo is available for 5900 somoni. Would you like to know more about its features or how to purchase it?', 'Lenovo is available for 5900 somoni.'],
            ["You're welcome! Take your time to decide, and feel free to reach out if you have any questions later.", "You're welcome!"],
            ['Ташаккур! Агар ба ягон маълумоти дигар ё кумак ниёз дошта бошед, ман дар хизмати шумо ҳастам.', 'Ташаккур!'],
        ] as [$input, $expected]) {
            $this->assertSame($expected, $cleaner->clean($input));
        }
    }

    public function test_facts_contact_requests_and_specific_advice_are_not_deleted(): void
    {
        $cleaner = new ReplyStyleCleaner;
        foreach ([
            'Цена 5900 сомони. На какой номер телефона связаться?',
            'Экран 15.6 дюйма. Какая программа нужна для учёбы?',
            'Если у вас возникнут вопросы, звоните +992 90 123 45 67.',
            'Если вам нужен чехол для Lenovo, могу помочь выбрать размер.',
            'Если у вас возникнут вопросы, мастерская работает только по субботам.',
            'If you have questions, the workshop is closed on Mondays.',
            'If you need a larger SSD, the Acer has 512GB.',
            'Агар ба шумо ноутбук барои кор лозим бошад, Lenovo 5900 сомонӣ аст.',
            'Would you like delivery to this address?',
            'Заказ записан. Напишите адрес доставки.',
            'Как вы хотите поступить?',
            'Пожалуйста!',
        ] as $input) {
            $this->assertSame($input, $cleaner->clean($input));
        }
    }
}
