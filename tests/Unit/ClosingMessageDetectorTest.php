<?php

namespace Tests\Unit;

use App\Services\Quality\ClosingMessageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClosingMessageDetectorTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function closings(): array
    {
        return [
            'tg plain keyboard' => ['Фахмо рахмат'],
            'tg with letters' => ['Фаҳмо шуд, раҳмат 🙏'],
            'tg tashakkur' => ['ташаккур'],
            'tg hub' => ['хуб'],
            'ru thanks' => ['спасибо'],
            'ru thanks a lot' => ['Спасибо большое!'],
            'ru understood' => ['Понятно'],
            'ru all clear' => ['всё ясно, спасибо'],
            'en thanks' => ['thanks!'],
            'en got it' => ['Got it, thank you'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function midFlow(): array
    {
        return [
            'thanks plus question' => ['Спасибо, а сколько стоит доставка?'],
            'tg question' => ['Кади намудхои ноутбукро доред'],
            'ru question' => ['А где вы находитесь'],
            'bare ok' => ['ок'],
            'bare agreement' => ['хорошо'],
            'yes' => ['да'],
            'emoji only' => ['👍'],
            'empty' => [''],
        ];
    }

    #[DataProvider('closings')]
    public function test_recognises_a_closing_message(string $text): void
    {
        $this->assertTrue((new ClosingMessageDetector)->matches($text));
    }

    #[DataProvider('midFlow')]
    public function test_ignores_a_message_that_is_not_closing(string $text): void
    {
        $this->assertFalse((new ClosingMessageDetector)->matches($text));
    }
}
