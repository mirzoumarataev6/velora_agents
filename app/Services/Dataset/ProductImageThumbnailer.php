<?php

namespace App\Services\Dataset;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Two things done at upload time with the same GD primitives, for the same
 * reason — a catalog photo is sent to a customer (the full original, via a
 * channel driver) and shown to the owner (a small thumbnail, in the Каталог
 * tab) far more often than it is uploaded:
 *
 *   1. downscale() shrinks an original bigger than config('agent.product_images.max_dimension_px')
 *      on its long side in place, so every later channel send stays light —
 *      a phone photo routinely arrives at 3000-4000px, and nothing a
 *      customer-facing chat shows needs that.
 *   2. thumbnail() builds a small (160px by default) webp/jpeg copy next to
 *      the original for the admin dashboard alone — see
 *      ProductImage::previewUrl().
 *
 * GD, not Intervention Image: this app's composer.json has neither
 * intervention/image nor any other image library installed, and the PHP
 * build here has the gd extension loaded — so GD's own functions are the
 * only dependency-free option. Nothing here is Intervention-specific; if
 * intervention/image is ever added for another reason, swapping it in only
 * touches this one class.
 *
 * Every public method fails soft (returns null / leaves the file alone) on
 * anything GD can't decode — a corrupt or exotic upload still gets to keep
 * its original file; it just does without a thumbnail or a resize.
 */
class ProductImageThumbnailer
{
    /** Shrinks the file at $path in place if its long side exceeds the configured maximum; a no-op otherwise. */
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

    /**
     * Builds a small preview of $path and stores it next to the original —
     * same directory, `-thumb` suffix, `.webp` (or `.jpg` if this GD build
     * cannot write webp). Returns the new file's path relative to the disk,
     * or null if the source could not be decoded.
     */
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

    /** @return array{0: int, 1: int, 2: int}|null [width, height, IMAGETYPE_*] */
    private function load(string $absolutePath): ?array
    {
        $info = @getimagesize($absolutePath);

        return $info === false ? null : [$info[0], $info[1], $info[2]];
    }

    /** @return \GdImage|null */
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

    /** @param \GdImage $resource @return \GdImage */
    private function resize($resource, int $width, int $height, int $maxDimension)
    {
        if (max($width, $height) <= $maxDimension) {
            return $resource;
        }

        $ratio = $maxDimension / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        // Preserves transparency for PNG/WEBP/GIF sources instead of
        // compositing them onto an opaque black canvas.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $resource, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /** @param \GdImage $resource */
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
