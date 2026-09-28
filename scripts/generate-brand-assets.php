<?php

/**
 * Derives every web-ready brand asset from the master Velora art in
 * public/images/brand/ (velora-logo-{light,dark}.png — full lockup with
 * wordmark; velora-mark-{light,dark}.png — icon-only V mark).
 *
 * The masters are opaque (white or black background), so this script first
 * recovers a transparent version by "un-blending": for art composited over
 * pure black, alpha = max(R,G,B)/255 and the true colour is pixel/alpha; over
 * pure white the mirror formula applies. That yields clean anti-aliased edges
 * without any hand masking.
 *
 * Outputs (all regenerated, safe to re-run):
 *   public/images/brand/gen/mark.png            512px transparent V mark
 *   public/images/brand/gen/lockup-light.png    lockup for LIGHT backgrounds (dark text)
 *   public/images/brand/gen/lockup-dark.png     lockup for DARK backgrounds (white text)
 *   public/images/brand/gen/favicon-{16,32,48}.png
 *   public/images/brand/gen/apple-touch-icon.png  180px, warm paper background
 *   public/images/brand/gen/icon-{192,512}.png    PWA icons (transparent, padded)
 *   public/images/brand/gen/og-image.png          1200x630 social preview
 *   public/favicon.ico                            16+32+48 (PNG-encoded entries)
 *   public/site.webmanifest
 *
 * Run:  php scripts/generate-brand-assets.php
 */
if (! extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$brandDir = $root.'/public/images/brand';
$genDir = $brandDir.'/gen';

if (! is_dir($genDir) && ! mkdir($genDir, 0775, true)) {
    fwrite(STDERR, "Cannot create {$genDir}\n");
    exit(1);
}

/**
 * Recover a transparent image from art composited over a uniform background.
 *
 * @param  'black'|'white'  $background
 */
function unblend(GdImage $src, string $background): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $transparent);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($src, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            if ($background === 'black') {
                // c = a * colour  =>  a = max channel (white/orange art).
                $alpha = max($r, $g, $b) / 255;
            } else {
                // c = a * colour + (1 - a) * 255  =>  a = 1 - min/255.
                $alpha = 1 - (min($r, $g, $b) / 255);
            }

            // Kill compression/AI noise in the flat background.
            if ($alpha < 0.05) {
                continue;
            }
            $alpha = min(1.0, $alpha * 1.02); // snap near-solid pixels to solid

            if ($background === 'black') {
                $tr = (int) min(255, $r / $alpha);
                $tg = (int) min(255, $g / $alpha);
                $tb = (int) min(255, $b / $alpha);
            } else {
                $tr = (int) max(0, min(255, ($r - (1 - $alpha) * 255) / $alpha));
                $tg = (int) max(0, min(255, ($g - (1 - $alpha) * 255) / $alpha));
                $tb = (int) max(0, min(255, ($b - (1 - $alpha) * 255) / $alpha));
            }

            $gdAlpha = (int) round(127 - $alpha * 127);
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $tr, $tg, $tb, $gdAlpha));
        }
    }

    return $out;
}

/** Crop a transparent image to its visible bounding box plus a relative margin. */
function cropToContent(GdImage $img, float $marginRatio = 0.04): GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F;
            if ($alpha < 120) { // visible enough
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }
    }

    if ($maxX < 0) {
        return $img; // fully transparent — nothing to crop
    }

    $margin = (int) round(max($maxX - $minX, $maxY - $minY) * $marginRatio);
    $minX = max(0, $minX - $margin);
    $minY = max(0, $minY - $margin);
    $maxX = min($w - 1, $maxX + $margin);
    $maxY = min($h - 1, $maxY + $margin);

    $cw = $maxX - $minX + 1;
    $ch = $maxY - $minY + 1;
    $out = imagecreatetruecolor($cw, $ch);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopy($out, $img, 0, 0, $minX, $minY, $cw, $ch);

    return $out;
}

/** Resample onto a transparent canvas of the given size (fit, centered). */
function fitOnCanvas(GdImage $img, int $canvasW, int $canvasH, float $scaleRatio = 1.0): GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min($canvasW / $w, $canvasH / $h) * $scaleRatio;
    $dw = (int) round($w * $scale);
    $dh = (int) round($h * $scale);

    $out = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, (int) (($canvasW - $dw) / 2), (int) (($canvasH - $dh) / 2), 0, 0, $dw, $dh, $w, $h);

    return $out;
}

function savePng(GdImage $img, string $path): void
{
    imagesavealpha($img, true);
    imagepng($img, $path, 9);
    echo sprintf("  %-52s %6.1f KB\n", str_replace(dirname(__DIR__).'/', '', $path), filesize($path) / 1024);
}

/** WebP sibling for web delivery (PNG stays as the fallback in <picture>). */
function saveWebp(GdImage $img, string $path): void
{
    imagesavealpha($img, true);
    imagewebp($img, $path, 82);
    echo sprintf("  %-52s %6.1f KB\n", str_replace(dirname(__DIR__).'/', '', $path), filesize($path) / 1024);
}

/** Build a .ico whose entries are PNG-encoded (understood by all modern browsers). */
function writeIco(array $pngPaths, string $icoPath): void
{
    $entries = '';
    $blobs = '';
    $offset = 6 + 16 * count($pngPaths);

    foreach ($pngPaths as $size => $path) {
        $data = file_get_contents($path);
        $entries .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($data), $offset);
        $blobs .= $data;
        $offset += strlen($data);
    }

    file_put_contents($icoPath, pack('vvv', 0, 1, count($pngPaths)).$entries.$blobs);
    echo sprintf("  %-52s %6.1f KB\n", str_replace(dirname(__DIR__).'/', '', $icoPath), filesize($icoPath) / 1024);
}

echo "Deriving transparent art...\n";

$markTransparent = cropToContent(unblend(imagecreatefrompng($brandDir.'/velora-mark-dark.png'), 'black'));
$lockupForLight = cropToContent(unblend(imagecreatefrompng($brandDir.'/velora-logo-light.png'), 'white'), 0.02);
$lockupForDark = cropToContent(unblend(imagecreatefrompng($brandDir.'/velora-logo-dark.png'), 'black'), 0.02);

echo "Writing outputs...\n";

// Core transparent assets.
$markCanvas = fitOnCanvas($markTransparent, 512, 512);
savePng($markCanvas, $genDir.'/mark.png');
saveWebp($markCanvas, $genDir.'/mark.webp');

$lockupW = 640;
foreach (['lockup-light.png' => $lockupForLight, 'lockup-dark.png' => $lockupForDark] as $name => $img) {
    $scaleH = (int) round(imagesy($img) * $lockupW / imagesx($img));
    $resized = imagecreatetruecolor($lockupW, $scaleH);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $lockupW, $scaleH, imagesx($img), imagesy($img));
    savePng($resized, $genDir.'/'.$name);
    saveWebp($resized, $genDir.'/'.str_replace('.png', '.webp', $name));
}

// Favicons (transparent mark, slight padding so the V doesn't touch the tab edge).
$faviconPngs = [];
foreach ([16, 32, 48] as $size) {
    $path = $genDir."/favicon-{$size}.png";
    savePng(fitOnCanvas($markTransparent, $size, $size, 0.92), $path);
    $faviconPngs[$size] = $path;
}
writeIco($faviconPngs, $root.'/public/favicon.ico');

// PWA icons.
savePng(fitOnCanvas($markTransparent, 192, 192, 0.82), $genDir.'/icon-192.png');
savePng(fitOnCanvas($markTransparent, 512, 512, 0.82), $genDir.'/icon-512.png');

// Apple touch icon: iOS composites onto an opaque tile, so give it the warm paper bg.
$apple = imagecreatetruecolor(180, 180);
imagefill($apple, 0, 0, imagecolorallocate($apple, 0xFA, 0xF9, 0xF5));
$appleMark = fitOnCanvas($markTransparent, 180, 180, 0.72);
imagealphablending($apple, true);
imagecopy($apple, $appleMark, 0, 0, 0, 0, 180, 180);
savePng($apple, $genDir.'/apple-touch-icon.png');

// OG / social preview: warm dark canvas with a soft radial glow + the dark lockup.
$og = imagecreatetruecolor(1200, 630);
$cx = 600;
$cy = 140;
$maxDist = 900;
for ($y = 0; $y < 630; $y++) {
    for ($x = 0; $x < 1200; $x++) {
        $t = min(1, sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $maxDist);
        // night-raised #2b2620 (glow centre) -> night canvas #1a1815 (edges)
        $r = (int) (0x2B + (0x1A - 0x2B) * $t);
        $g = (int) (0x26 + (0x18 - 0x26) * $t);
        $b = (int) (0x20 + (0x15 - 0x20) * $t);
        imagesetpixel($og, $x, $y, imagecolorallocate($og, $r, $g, $b));
    }
}
$ogLockup = $lockupForDark;
$targetH = 470;
$targetW = (int) round(imagesx($ogLockup) * $targetH / imagesy($ogLockup));
imagealphablending($og, true);
imagecopyresampled($og, $ogLockup, (int) ((1200 - $targetW) / 2), (int) ((630 - $targetH) / 2), 0, 0, $targetW, $targetH, imagesx($ogLockup), imagesy($ogLockup));
savePng($og, $genDir.'/og-image.png');

// Web app manifest.
file_put_contents($root.'/public/site.webmanifest', json_encode([
    'name' => 'Velora Agents',
    'short_name' => 'Velora',
    'icons' => [
        ['src' => '/images/brand/gen/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/images/brand/gen/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
    // Mirrors `brand` (ember-500) and `paper` (sand-50) in tailwind.config.js.
    // This file had gone stale on the retired #da7756 once already — a rerun
    // would have silently reintroduced a colour BrandConsistencyTest bans.
    'theme_color' => '#e27551',
    'background_color' => '#faf9f5',
    'display' => 'standalone',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "  public/site.webmanifest\n";

echo "Done.\n";
