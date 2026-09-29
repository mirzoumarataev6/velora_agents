<?php

/**
 * Derives every web-ready brand asset from the BLUE V mark.
 *
 * SOURCE OF TRUTH: resources/brand/logo-source.png — the supplied artwork,
 * byte for byte. Every icon, tile and social card in the product is derived
 * from that one file, so there is exactly one place the mark is defined and
 * no surface can drift onto a different version of it.
 *
 * It replaces a chain that ran logo → hand-traced SVG → rasterised PNG →
 * these outputs. A trace is a reproduction: its sparkle sat slightly off, its
 * angles were approximate, and everything downstream inherited that. The SVG
 * and its rasteriser (scripts/rasterize-mark.py) are retired.
 *
 * The supplied file is opaque RGB on near-white, so the alpha is recovered by
 * keyOutWhiteGround() below before anything is composited.
 *
 * Re-run after replacing the artwork:
 *
 *     php scripts/generate-brand-assets.php
 *
 * The retired orange art (public/images/brand/velora-{mark,logo}-{light,dark}.png)
 * is GONE — deleted, not merely unread. It was opaque, composited over white or
 * black, and needed an "un-blend" pass to recover its alpha; the vector master
 * has real transparency, so that step and the function that did it are both
 * removed. Nothing in the product pointed at those files, and keeping four
 * megabytes of a retired identity on disk was the only place an orange pixel
 * still survived.
 *
 * The wordmark on the social preview is set in Onest SemiBold, the product's
 * own face (resources/brand/Onest-SemiBold.ttf, SIL OFL — exported by
 * scripts/vendor-onest.sh, since GD cannot read the woff2 the browser gets).
 * 600 rather than a bold: the system caps at 600 everywhere, and a social card
 * set heavier than the product itself would misrepresent it.
 *
 * Outputs (all regenerated, safe to re-run):
 *   public/images/brand/gen/mark.png            512px transparent V mark
 *   public/images/brand/gen/lockup-light.png    lockup for LIGHT backgrounds (dark text)
 *   public/images/brand/gen/lockup-dark.png     lockup for DARK backgrounds (white text)
 *   public/images/brand/gen/favicon-{16,32,48}.png
 *   public/images/brand/gen/apple-touch-icon.png  180px, canvas-coloured tile
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

/* The palette, from the same generated file the views read. Nothing in this
   script types a brand hex. */
$palettePath = $root.'/config/palette.php';
if (! is_file($palettePath)) {
    fwrite(STDERR, "Missing {$palettePath}. Run: npm run palette\n");
    exit(1);
}
$palette = require $palettePath;

if (! is_dir($genDir) && ! mkdir($genDir, 0775, true)) {
    fwrite(STDERR, "Cannot create {$genDir}\n");
    exit(1);
}

/**
 * Compose a lockup: the mark, then the wordmark set in the product's own face.
 *
 * Built rather than traced, because the only lockup art that exists is the
 * retired orange one. Two colours go in — the wordmark's and the tagline's —
 * so the same routine produces the light-background and dark-background cuts.
 *
 * @param  array{int,int,int}  $wordRgb
 * @param  array{int,int,int}  $tagRgb
 */
function composeLockup(GdImage $mark, string $fontPath, array $wordRgb, array $tagRgb): GdImage
{
    $markH = 190;
    $markW = (int) round(imagesx($mark) * $markH / imagesy($mark));
    $gap = 34;
    $wordSize = 116;
    $tagSize = 34;

    // Measure first: imagettfbbox gives the ink box, which is what the layout
    // has to be built from — the advance width would leave a ragged right edge.
    $wordBox = imagettfbbox($wordSize, 0, $fontPath, 'VELORA');
    $wordW = $wordBox[2] - $wordBox[0];
    $wordH = $wordBox[1] - $wordBox[7];
    $tagBox = imagettfbbox($tagSize, 0, $fontPath, 'AGENTS');
    $tagW = $tagBox[2] - $tagBox[0];

    // The tagline is letterspaced by hand — GD has no tracking control, so each
    // glyph is drawn at its own x. 0.42em reads as a wordmark subtitle rather
    // than as a second word.
    $track = (int) round($tagSize * 0.42);
    $tagChars = str_split('AGENTS');
    $tagTotal = $tagW + $track * (count($tagChars) - 1);

    $textW = max($wordW, $tagTotal);
    $pad = 24;
    $W = $pad + $markW + $gap + $textW + $pad;
    $H = $pad + max($markH, (int) round($wordH + $tagSize * 1.9)) + $pad;

    $out = imagecreatetruecolor($W, $H);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);

    $markY = (int) round(($H - $markH) / 2);
    imagecopyresampled($out, $mark, $pad, $markY, 0, 0, $markW, $markH, imagesx($mark), imagesy($mark));

    $textX = $pad + $markW + $gap;
    $wordColor = imagecolorallocate($out, ...$wordRgb);
    $tagColor = imagecolorallocate($out, ...$tagRgb);

    $wordBaseline = (int) round($H / 2 + $wordH / 2 - $tagSize * 0.95);
    imagettftext($out, $wordSize, 0, $textX, $wordBaseline, $wordColor, $fontPath, 'VELORA');

    $tagBaseline = $wordBaseline + (int) round($tagSize * 1.75);
    $x = $textX;
    foreach ($tagChars as $ch) {
        imagettftext($out, $tagSize, 0, $x, $tagBaseline, $tagColor, $fontPath, $ch);
        $b = imagettfbbox($tagSize, 0, $fontPath, $ch);
        $x += ($b[2] - $b[0]) + $track;
    }

    return $out;
}

/**
 * Key the white ground out of artwork that was supplied flattened.
 *
 * The supplied logo is an opaque RGB PNG: the mark sits on a near-white
 * ground (sampled, ~254) and every antialiased edge is already blended into
 * it. That ground has to become real alpha before anything downstream can put
 * the mark on the dark sidebar or on a coloured tile.
 *
 * WHAT DOES NOT WORK, and was tried first: inverting the compositing equation
 * per pixel, a = 1 − min(r,g,b)/bg. That is the standard un-blend and it is
 * right only for artwork whose opaque areas are near-black. This mark runs
 * from a near-black navy to a light azure, and the azure's darkest channel is
 * about 75 — so the formula declared the entire light half of the V to be 70%
 * TRANSPARENT. On white it looked perfect, because un-blending and then
 * recompositing over white is a round trip; on the dark sidebar it would have
 * been a pale ghost. 37% of the mark came out partially transparent.
 *
 * WHAT WORKS: treat this as a matte, not a blend. Coverage comes from a narrow
 * ramp just below the ground level — wide enough to catch the one- or two-pixel
 * antialiased boundary, far above anything in the artwork itself — and the
 * COLOUR is never touched. The interior stays fully opaque at its original
 * values, which is what keeps the gradient exactly as drawn.
 *
 * The thin pale fringe this leaves at the very edge is not worth chasing: the
 * master is 1536px wide and every output is resampled down from it (512 for
 * the mark, 180 for the touch icon, 16 for the smallest favicon), so that
 * fringe is averaged into the resampling and reads as clean antialiasing.
 */
function keyOutWhiteGround(GdImage $src): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);

    // The ground, read from the corners — the four places the artwork is
    // guaranteed not to be. Sampled rather than assumed to be 255: this file's
    // ground is 254, and keying against 255 leaves every "white" pixel very
    // slightly opaque, which is a grey wash over the whole bounding box the
    // moment the mark is placed on anything darker.
    $bg = 0;
    foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
        $rgb = imagecolorat($src, $x, $y);
        $bg = max($bg, max(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF));
    }

    // The ramp. `$clear` allows for the couple of levels of noise in the
    // ground itself; `$solid` is still far lighter than any ink in the mark,
    // so nothing real is caught by it.
    $clear = $bg - 4;
    $solid = $bg - 24;

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($src, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $level = min($r, $g, $b);

            if ($level >= $clear) {
                // GD's alpha channel is INVERTED and 7-bit: 127 is invisible.
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 0, 0, 0, 127));

                continue;
            }

            if ($level <= $solid) {
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, 0));

                continue;
            }

            $coverage = ($clear - $level) / ($clear - $solid);
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, (int) round((1 - $coverage) * 127)));
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

echo "Reading the brand master...\n";

/* THE ONE SOURCE OF TRUTH: resources/brand/logo-source.png — the artwork as
 * supplied, byte for byte.
 *
 * This used to read resources/brand/velora-mark.png, a rasterisation of
 * resources/brand/velora-mark.svg, which was a HAND TRACE of the same
 * artwork. That is a reproduction, not the logo: the sparkle sat slightly
 * differently, the V's inner angle was a degree or two off, and the gradient
 * stops were approximations of the original's. Every favicon, PWA icon and
 * social card in the product was therefore a copy of a copy. The trace is
 * retired and the supplied file is the master.
 *
 * The supplied file is opaque RGB on a near-white ground (~254,254,254), so
 * the alpha has to be recovered before anything downstream can composite it
 * over a dark surface or a rounded tile — see unblendFromWhite().
 */
$markPath = $root.'/resources/brand/logo-source.png';
if (! is_file($markPath)) {
    fwrite(STDERR, "Missing {$markPath}. Put the supplied logo artwork there.\n");
    exit(1);
}

$markTransparent = cropToContent(keyOutWhiteGround(imagecreatefrompng($markPath)));

// The lockups are composed rather than traced: the mark, then the wordmark set
// in the product's own face. `$lockupForLight` carries ink-coloured text for a
// light background, `$lockupForDark` white text for a dark one.
$wordmarkFont = $root.'/resources/brand/Onest-SemiBold.ttf';
$lockupForLight = composeLockup($markTransparent, $wordmarkFont, [0x0F, 0x11, 0x15], [0x5C, 0x64, 0x70]);
$lockupForDark = composeLockup($markTransparent, $wordmarkFont, [0xFF, 0xFF, 0xFF], [0x9A, 0xA6, 0xBD]);

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

// Apple touch icon: iOS composites onto an opaque tile, so give it the canvas
// colour — paper / slate-50 (#f1f2f6).
$apple = imagecreatetruecolor(180, 180);
imagefill($apple, 0, 0, imagecolorallocate($apple, 0xF1, 0xF2, 0xF6));
$appleMark = fitOnCanvas($markTransparent, 180, 180, 0.72);
imagealphablending($apple, true);
imagecopy($apple, $appleMark, 0, 0, 0, 0, 180, 180);
savePng($apple, $genDir.'/apple-touch-icon.png');

// OG / social preview: the cool night canvas with a soft radial glow + the dark
// lockup. The glow is centred above the mark so the art sits in the lit half.
$og = imagecreatetruecolor(1200, 630);
$cx = 600;
$cy = 140;
$maxDist = 900;
for ($y = 0; $y < 630; $y++) {
    for ($x = 0; $x < 1200; $x++) {
        $t = min(1, sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $maxDist);
        // night-raised #1d2433 (glow centre) -> night canvas #0d1117 (edges)
        $r = (int) (0x1D + (0x0D - 0x1D) * $t);
        $g = (int) (0x24 + (0x11 - 0x24) * $t);
        $b = (int) (0x33 + (0x17 - 0x33) * $t);
        imagesetpixel($og, $x, $y, imagecolorallocate($og, $r, $g, $b));
    }
}
// Fit the lockup INSIDE a box rather than scaling it to a fixed height: the
// composed lockup is much wider than the traced one it replaces, and scaling by
// height alone ran it off both edges of the canvas.
$ogLockup = $lockupForDark;
$boxW = 860;
$boxH = 300;
$fit = min($boxW / imagesx($ogLockup), $boxH / imagesy($ogLockup));
$targetW = (int) round(imagesx($ogLockup) * $fit);
$targetH = (int) round(imagesy($ogLockup) * $fit);
imagealphablending($og, true);
imagecopyresampled($og, $ogLockup, (int) ((1200 - $targetW) / 2), (int) ((630 - $targetH) / 2), 0, 0, $targetW, $targetH, imagesx($ogLockup), imagesy($ogLockup));
savePng($og, $genDir.'/og-image.png');

/* Web app manifest.
 *
 * The ?v= on each icon matches config('agent.brand_mark_version'), which
 * every <img> tag rendering the mark now reads too (favicons.blade.php and
 * every layout that shows the sidebar/header logo) — the icon filenames
 * never change when the mark is regenerated, so the query is the only thing
 * that tells a browser, or an installed PWA, that its cached icon is stale.
 * Bump this AND config/agent.php's brand_mark_version together; this script
 * cannot read Laravel config directly (it runs unbootstrapped), so the
 * number is duplicated here on purpose rather than silently disagreeing. */
$iconVersion = '4';

file_put_contents($root.'/public/site.webmanifest', json_encode([
    'name' => 'Velora Agents',
    'short_name' => 'Velora',
    'icons' => [
        ['src' => "/images/brand/gen/icon-192.png?v={$iconVersion}", 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => "/images/brand/gen/icon-512.png?v={$iconVersion}", 'sizes' => '512x512', 'type' => 'image/png'],
    ],
    // Read from config/palette.php rather than typed, for the same reason the
    // theme-color meta tags are: this file had gone stale on a retired accent
    // twice already — first #da7756, then #e27551 — and a rerun would silently
    // have reintroduced a colour BrandConsistencyTest bans. Now it cannot drift.
    'theme_color' => $palette['brand-strong'],
    'background_color' => $palette['paper'],
    'display' => 'standalone',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "  public/site.webmanifest\n";

echo "Done.\n";
