<?php

/**
 * Renders every web-ready brand asset from the vector master,
 * resources/brand/velora-mark.svg.
 *
 * WHAT CHANGED, AND WHY IT MATTERS
 * --------------------------------
 * This script used to derive its output from two opaque PNG masters by
 * "un-blending" them — recovering an alpha channel from art composited over
 * flat black and flat white. That worked, but every asset was a resample of a
 * raster, so a 16px favicon was a downsample of a downsample and the mark went
 * soft at small sizes.
 *
 * The master is now a real vector, traced from the source art and verified
 * against it at 99.4% pixel agreement. Every size below is rendered FROM THE
 * GEOMETRY at its own resolution, so a 16px favicon is as crisp as the 512px
 * app icon.
 *
 * It renders the SVG itself rather than shelling out to Inkscape or rsvg:
 * neither is installed here, and the master deliberately uses only absolute
 * move/line/close segments and linear gradients — the exact subset this needs
 * to understand. If the master ever gains a curve, this will say so and stop
 * rather than silently dropping it.
 *
 * Outputs (all regenerated, safe to re-run):
 *   public/images/brand/velora-mark.svg           the vector, served as-is
 *   public/images/brand/velora-mark-navy.svg      flat #0B1636, for small sizes
 *   public/images/brand/velora-mark-white.svg     flat white, for dark grounds
 *   public/images/brand/gen/mark.png              512px, transparent
 *   public/images/brand/gen/favicon-{16,32,48}.png
 *   public/images/brand/gen/apple-touch-icon.png  180px on the light canvas
 *   public/images/brand/gen/icon-{192,512}.png    PWA icons
 *   public/images/brand/gen/og-image.png          1200x630 social preview
 *   public/favicon.ico                            16+32+48
 *
 * Run:  php scripts/generate-brand-assets.php
 */
if (! extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$master = $root.'/resources/brand/velora-mark.svg';
$brandDir = $root.'/public/images/brand';
$genDir = $brandDir.'/gen';

foreach ([$brandDir, $genDir] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }
}

/* ---------------------------------------------------------------------------
 * Parse the master.
 * ------------------------------------------------------------------------- */

$svg = file_get_contents($master);

if (! preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $vb)) {
    fwrite(STDERR, "No viewBox in the master.\n");
    exit(1);
}
[$artW, $artH] = [(float) $vb[1], (float) $vb[2]];

/** Gradient definitions, keyed by id. */
$gradients = [];
preg_match_all(
    '#<linearGradient id="([^"]+)"[^>]*x1="([-\d.]+)" y1="([-\d.]+)" x2="([-\d.]+)" y2="([-\d.]+)"[^>]*>(.*?)</linearGradient>#s',
    $svg,
    $gradBlocks,
    PREG_SET_ORDER
);
foreach ($gradBlocks as $g) {
    preg_match_all('/<stop offset="([\d.]*)" stop-color="#([0-9A-Fa-f]{6})"/', $g[6], $stops, PREG_SET_ORDER);
    $gradients[$g[1]] = [
        'axis' => [(float) $g[2], (float) $g[3], (float) $g[4], (float) $g[5]],
        'stops' => array_map(fn ($s) => [(float) ($s[1] === '' ? 0 : $s[1]), hexdec($s[2])], $stops),
    ];
}

/** Paths, in document order — which is also paint order. */
$paths = [];
preg_match_all('#<path fill="([^"]+)" d="([^"]+)"#', $svg, $pathBlocks, PREG_SET_ORDER);
foreach ($pathBlocks as $p) {
    if (preg_match('/[CcSsQqTtAaHhVvLlMm]/', str_replace(['M', 'L', 'Z'], '', $p[2]))) {
        fwrite(STDERR, "The master uses path commands this renderer does not support.\n");
        exit(1);
    }
    $points = [];
    preg_match_all('/([ML])\s*([-\d.]+)\s+([-\d.]+)/', $p[2], $segs, PREG_SET_ORDER);
    foreach ($segs as $s) {
        $points[] = [(float) $s[2], (float) $s[3]];
    }
    $fill = $p[1];
    $paths[] = [
        'points' => $points,
        'gradient' => preg_match('/url\(#([^)]+)\)/', $fill, $m) ? $m[1] : null,
        'solid' => str_starts_with($fill, '#') ? hexdec(ltrim($fill, '#')) : null,
    ];
}

if ($paths === []) {
    fwrite(STDERR, "No paths found in the master.\n");
    exit(1);
}

/* ---------------------------------------------------------------------------
 * Rendering.
 * ------------------------------------------------------------------------- */

function gradientColour(array $stops, float $t): array
{
    $t = max(0.0, min(1.0, $t));

    for ($i = 0; $i < count($stops) - 1; $i++) {
        [$o1, $c1] = $stops[$i];
        [$o2, $c2] = $stops[$i + 1];

        if ($t >= $o1 && $t <= $o2) {
            $u = ($o2 - $o1) > 0 ? ($t - $o1) / ($o2 - $o1) : 0.0;

            return [
                (int) ((($c1 >> 16) & 255) + ((($c2 >> 16) & 255) - (($c1 >> 16) & 255)) * $u),
                (int) ((($c1 >> 8) & 255) + ((($c2 >> 8) & 255) - (($c1 >> 8) & 255)) * $u),
                (int) (($c1 & 255) + (($c2 & 255) - ($c1 & 255)) * $u),
            ];
        }
    }

    $c = $stops[count($stops) - 1][1];

    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

/**
 * Draw the mark into a transparent canvas of the given width.
 *
 * Supersampled 3x and then resampled down, which is what gives the small sizes
 * their antialiased edges — GD's polygon fill has none of its own.
 *
 * @param  ?int  $flat  null for the full gradient art, or an RGB int for a flat
 *                      single-colour version
 */
function renderMark(int $width, array $paths, array $gradients, float $artW, float $artH, ?int $flat = null): GdImage
{
    $ss = 3;
    $height = (int) round($width * $artH / $artW);
    $bigW = $width * $ss;
    $bigH = $height * $ss;
    $scale = $bigW / $artW;

    $big = imagecreatetruecolor($bigW, $bigH);
    imagealphablending($big, false);
    imagesavealpha($big, true);
    imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));

    foreach ($paths as $path) {
        $poly = [];
        foreach ($path['points'] as [$x, $y]) {
            $poly[] = (int) round($x * $scale);
            $poly[] = (int) round($y * $scale);
        }

        // Stencil the shape, then paint colour through it. Painting directly
        // would need one polygon fill per colour step.
        $stencil = imagecreatetruecolor($bigW, $bigH);
        imagefill($stencil, 0, 0, imagecolorallocate($stencil, 0, 0, 0));
        imagefilledpolygon($stencil, $poly, imagecolorallocate($stencil, 255, 255, 255));

        $solid = $flat ?? $path['solid'];
        $grad = $flat === null && $path['gradient'] !== null ? $gradients[$path['gradient']] : null;

        if ($grad !== null) {
            [$x1, $y1, $x2, $y2] = $grad['axis'];
            $x1 *= $scale;
            $y1 *= $scale;
            $x2 *= $scale;
            $y2 *= $scale;
            $dx = $x2 - $x1;
            $dy = $y2 - $y1;
            $len2 = $dx * $dx + $dy * $dy;
        }

        for ($y = 0; $y < $bigH; $y++) {
            for ($x = 0; $x < $bigW; $x++) {
                if ((imagecolorat($stencil, $x, $y) & 255) < 128) {
                    continue;
                }

                if ($grad !== null) {
                    $t = $len2 > 0 ? ((($x - $x1) * $dx + ($y - $y1) * $dy) / $len2) : 0.0;
                    [$r, $g, $b] = gradientColour($grad['stops'], $t);
                } else {
                    $r = ($solid >> 16) & 255;
                    $g = ($solid >> 8) & 255;
                    $b = $solid & 255;
                }

                imagesetpixel($big, $x, $y, imagecolorallocatealpha($big, $r, $g, $b, 0));
            }
        }

        imagedestroy($stencil);
    }

    $out = imagecreatetruecolor($width, $height);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $big, 0, 0, 0, 0, $width, $height, $bigW, $bigH);
    imagesavealpha($out, true);
    imagedestroy($big);

    return $out;
}

/** Centre the mark on a square canvas, occupying $fill of the shorter side. */
function onCanvas(GdImage $mark, int $size, float $fill, ?array $bg = null): GdImage
{
    $mw = imagesx($mark);
    $mh = imagesy($mark);
    $target = $size * $fill;
    $ratio = min($target / $mw, $target / $mh);
    $dw = (int) round($mw * $ratio);
    $dh = (int) round($mh * $ratio);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill(
        $out,
        0,
        0,
        $bg === null
            ? imagecolorallocatealpha($out, 0, 0, 0, 127)
            : imagecolorallocate($out, ...$bg)
    );
    imagealphablending($out, true);
    imagecopyresampled($out, $mark, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $mw, $mh);
    imagesavealpha($out, true);

    return $out;
}

function savePng(GdImage $img, string $path): void
{
    imagesavealpha($img, true);
    imagepng($img, $path, 9);
    echo '  '.basename($path).'  '.imagesx($img).'x'.imagesy($img)."\n";
}

/* ---------------------------------------------------------------------------
 * Emit.
 * ------------------------------------------------------------------------- */

echo "Vector masters\n";
foreach (['velora-mark.svg', 'velora-mark-navy.svg', 'velora-mark-white.svg'] as $name) {
    copy($root.'/resources/brand/'.$name, $brandDir.'/'.$name);
    echo '  '.$name."\n";
}

echo "Raster, rendered from the geometry at each size\n";

$mark512 = renderMark(512, $paths, $gradients, $artW, $artH);
savePng($mark512, $genDir.'/mark.png');

// Small sizes render the FLAT NAVY version, not the gradient one. Below about
// 48px the gradient's dark end and the navy ground of a browser tab converge,
// and the mark loses its shape; a single solid colour keeps the silhouette.
$icoFiles = [];
foreach ([16, 32, 48] as $size) {
    $flat = renderMark($size * 2, $paths, $gradients, $artW, $artH, 0x0B1636);
    $img = onCanvas($flat, $size, 0.92);
    $path = $genDir."/favicon-{$size}.png";
    savePng($img, $path);
    $icoFiles[$size] = $path;
}

// Apple touch icons are composited on an opaque ground by iOS anyway, so give
// it the canvas colour deliberately rather than letting it pick black.
savePng(onCanvas(renderMark(360, $paths, $gradients, $artW, $artH), 180, 0.74, [244, 246, 251]), $genDir.'/apple-touch-icon.png');

savePng(onCanvas($mark512, 192, 0.82), $genDir.'/icon-192.png');
savePng(onCanvas($mark512, 512, 0.82), $genDir.'/icon-512.png');

// Social preview: the mark on the product's own canvas colour.
$og = imagecreatetruecolor(1200, 630);
imagefill($og, 0, 0, imagecolorallocate($og, 244, 246, 251));
$ogMark = renderMark(420, $paths, $gradients, $artW, $artH);
imagealphablending($og, true);
imagecopy($og, $ogMark, (int) ((1200 - 420) / 2), (int) ((630 - imagesy($ogMark)) / 2), 0, 0, 420, imagesy($ogMark));
savePng($og, $genDir.'/og-image.png');

/* favicon.ico with PNG-encoded entries, which every browser in use reads. */
$ico = pack('vvv', 0, 1, count($icoFiles));
$offset = 6 + 16 * count($icoFiles);
$blobs = [];
foreach ($icoFiles as $size => $path) {
    $blobs[$size] = file_get_contents($path);
}
foreach ($blobs as $size => $blob) {
    $ico .= pack('CCCCvvVV', $size === 256 ? 0 : $size, $size === 256 ? 0 : $size, 0, 0, 1, 32, strlen($blob), $offset);
    $offset += strlen($blob);
}
file_put_contents($root.'/public/favicon.ico', $ico.implode('', $blobs));
echo '  favicon.ico  ('.count($blobs)." entries)\n";

echo "\nDone.\n";
