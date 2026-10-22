<?php

namespace App\Services\Dataset;

use Illuminate\Contracts\Filesystem\Filesystem;

class ProductImageThumbnailer
{
    public function downscale(Filesystem $disk, string $path): void
    {
        $maxDimension = (int) config('agent.product_images.max_dimension_px');
        $absolutePath = $disk->path($path);

        $image = $this->load($absolutePath);
        if ($image === null) {
            return;
        }

        [$width, $height, $type] = $image;
        if (max($width, $height) <= $maxDimension) {
            return;
        }

        $resource = $this->decode($absolutePath, $type);
        if ($resource === null) {
            return;
        }

        $resized = $this->resize($resource, $width, $height, $maxDimension);
        if ($resized !== $resource) {
            imagedestroy($resource);
        }

        $this->encode($resized, $absolutePath, $type);
        imagedestroy($resized);
    }

    public function thumbnail(Filesystem $disk, string $path): ?string
    {
        $maxDimension = (int) config('agent.product_images.thumbnail_max_dimension_px');
        $absolutePath = $disk->path($path);

        $image = $this->load($absolutePath);
        if ($image === null) {
            return null;
        }

        [$width, $height, $type] = $image;
        $resource = $this->decode($absolutePath, $type);
        if ($resource === null) {
            return null;
        }

        $resized = $this->resize($resource, $width, $height, $maxDimension);
        if ($resized !== $resource) {
            imagedestroy($resource);
        }

        $useWebp = function_exists('imagewebp');
        $extension = $useWebp ? 'webp' : 'jpg';
        $thumbnailPath = preg_replace('/\.[^.\/]+$/', '', $path)."-thumb.{$extension}";

        $this->encode($resized, $disk->path($thumbnailPath), $useWebp ? IMAGETYPE_WEBP : IMAGETYPE_JPEG);
        imagedestroy($resized);

        return $thumbnailPath;
    }

    private function load(string $absolutePath): ?array
    {
        $info = @getimagesize($absolutePath);

        return $info === false ? null : [$info[0], $info[1], $info[2]];
    }

    private function decode(string $absolutePath, int $type)
    {
        $resource = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
            IMAGETYPE_PNG => @imagecreatefrompng($absolutePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($absolutePath),
            default => false,
        };

        return $resource === false ? null : $resource;
    }

    private function resize($resource, int $width, int $height, int $maxDimension)
    {
        if (max($width, $height) <= $maxDimension) {
            return $resource;
        }

        $ratio = $maxDimension / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $resource, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    private function encode($resource, string $absolutePath, int $type): void
    {
        $quality = (int) config('agent.product_images.thumbnail_quality');

        match ($type) {
            IMAGETYPE_PNG => imagepng($resource, $absolutePath),
            IMAGETYPE_WEBP => imagewebp($resource, $absolutePath, $quality),
            IMAGETYPE_GIF => imagegif($resource, $absolutePath),
            default => imagejpeg($resource, $absolutePath, $quality),
        };
    }
}
