<?php

namespace App\Services\Channels;

use Throwable;

trait DescribesImagesWithConfidence
{
    protected const VISION_UNKNOWN_SENTINEL = 'UNKNOWN';

    protected function describeImageBytes(string $bytes, string $mimeType): array
    {
        $unclear = ['confident' => false, 'description' => null];
        $tmpPath = tempnam(sys_get_temp_dir(), 'vision_img_');

        try {
            file_put_contents($tmpPath, $bytes);

            $result = $this->llm->extractFromImage(
                $tmpPath,
                'This photo was sent by a customer to a business support chat. '
                    .'If you can clearly identify a specific product, brand, or model relevant to a purchase '
                    .'inquiry, respond with ONLY that identification in a few words (e.g. "Nike Air Max sneakers, black" '
                    .'or "Samsung Galaxy S23 box"). If the image is too dark, blurry, generic, unrelated to a product, '
                    .'or you cannot confidently tell what specific product is being asked about, respond with EXACTLY '
                    .'the single word: '.self::VISION_UNKNOWN_SENTINEL,
                $mimeType
            );

            $text = trim($result['text']);

            if ($text === '' || strcasecmp($text, self::VISION_UNKNOWN_SENTINEL) === 0) {
                return $unclear;
            }

            return ['confident' => true, 'description' => $text];
        } catch (Throwable) {
            return $unclear;
        } finally {
            @unlink($tmpPath);
        }
    }
}
