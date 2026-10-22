<?php

namespace App\Services\Channels;

class ImageRequestDetector
{
    public function requested(string $text): bool
    {

        if (str_starts_with($text, '[Customer sent an image')) {
            return false;
        }

        return preg_match(
            '/(?:фото|фотограф|картинк|изображен|расм|акси|сурат|photo|picture|image'
            .'|(?:показ(?:ать|и|ите)|покаж(?:и|ите)).{0,40}(?:вид|выгляд|фото|картинк|изображен)'
            .'|(?:как|how).{0,20}(?:они|он|она|это|they|it)?\s*(?:выгляд\w*|look\w*)'
            .'|show.{0,30}(?:photo|picture|image|what.{0,10}look))/iu',
            $text,
        ) === 1;
    }
}
