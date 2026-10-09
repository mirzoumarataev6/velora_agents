<?php

namespace Tests\Unit\Channels;

use App\Services\Channels\ImageRequestDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageRequestDetectorTest extends TestCase
{
    #[DataProvider('photoRequests')]
    public function test_it_recognizes_natural_photo_requests(string $message): void
    {
        $this->assertTrue((new ImageRequestDetector)->requested($message));
    }

    public static function photoRequests(): array
    {
        return [
            'Russian explicit photo' => ['А можете отправить фото?'],
            'Russian indirect appearance' => ['А можете показать как они выглядят'],
            'Russian show the picture' => ['Покажите, пожалуйста, фотографию ноутбука'],
            'Tajik photo' => ['Лутфан акси ноутбукро нишон диҳед'],
            'English photo' => ['Can you show me what they look like?'],
        ];
    }

    public function test_a_normal_product_question_does_not_trigger_photo_delivery(): void
    {
        $this->assertFalse((new ImageRequestDetector)->requested('Они подходят для современных игр?'));
    }

    public function test_inbound_image_note_is_not_a_photo_request(): void
    {
        $this->assertFalse((new ImageRequestDetector)->requested('[Customer sent an image. Identified: a laptop]'));
    }
}
