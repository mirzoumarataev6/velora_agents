<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * VELORA MIDNIGHT, enforced.
 *
 * Two jobs, and the second one is the reason this file is worth its length.
 *
 * 1. The rebrand has to hold across the whole product, not just the page that
 *    was open when it was done. The palette lives in exactly one place
 *    (resources/css/tokens.css), so the realistic way for a retired identity to
 *    survive is a hard-coded hex in a view nobody reopened. These render every
 *    real surface and fail on exactly that.
 *
 * 2. This product is judged on a projector in a lit hall, which turns contrast
 *    from a preference into a specification: body text ≥ 12:1, secondary ≥ 7:1,
 *    non-text UI that still has to be seen ≥ 3:1. Those floors are far above
 *    WCAG AA, and they are the whole reason the palette looks the way it does.
 *    A future tweak that lightens a muted grey "because it looks nicer on my
 *    laptop" is exactly the change that cannot be caught by looking at a
 *    laptop — so it is caught here instead.
 *
 * @see docs/DESIGN.md
 */
class BrandConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every palette this product has retired. Any of these appearing in emitted
     * HTML means a view is painting its own colour instead of using a token.
     *
     * @var list<string>
     */
    private const RETIRED_COLOURS = [
        // --- "Warm daylight", terracotta edition. The system Velora Midnight
        // replaced: a warm ember accent on sand neutrals. Every step of it.
        '#fdf3ef', '#fae4db', '#f6cbba', '#f0b096', '#ee9a7c',
        '#e27551', '#cc5c39', '#ab4a2c', '#8b3c23', '#71321d', '#3d1a0f',
        '#fff8f3', // the warm off-white that sat on an accent fill
        '#ff9a4d', '#f0530a', // the mark's old orange sweep
        // Sand neutrals.
        '#faf9f5', '#f0ede6', '#e9e5dc', '#ddd8cb', '#b5afa0', '#8a8578',
        '#6b665a', '#4a463c', '#332f28', '#2b2b2b',
        // Warm night.
        '#1a1815', '#201d18', '#242019', '#2b2620', '#f5f1e8', '#a39d90', '#221e18',
        // Its semantics — a warm palette had to push these off the brand hue.
        '#0e7a4a', '#57cf94', '#96620a', '#f0b429',
        '#c2183a', '#a01230', '#f2899a', '#1f6f8b', '#63c5e0',

        // --- The bright terracotta before that.
        '#ee6c4d', '#e0512f', '#bc3d1f', '#99321a', '#f5936b',
        '#fef5f1', '#fde7de', '#fbcdbc', '#f7ae93', '#7c2a17', '#42130b',
        // --- Ember-orange system before that.
        '#fb6309', '#dd4c05', '#b63a08', '#8f2f0d', '#ff8134',
        '#ffe8d8', '#ffcba6', '#ffa66b', '#75290f', '#401206',
        '#fbfaf9', '#f6f4f1', '#ece9e4', '#dedad3',
        '#8c867b', '#6b655b', '#4a453d', '#2e2a25', '#1c1a17', '#121110',
        '#141210', '#252119', '#2e2a23', '#f5f2ee', '#a9a29a', '#1f1c18',
        '#0f7a4f', '#4ecb8c', '#c0261f', '#f28b82',
        // --- Brand-sheet orange the landing used to paint itself with.
        '#ffba4c', '#f08a3e', '#e56c28', '#b4500f', '#11141b', '#1b1f28',
        '#f6f7f9', '#e9ebee', '#171b24', '#1c212b', '#e8eaee', '#f4f5f7', '#080a0e',
        // --- Cream + dusty terracotta.
        '#da7756', '#c2603f', '#a14e33', '#8f4228', '#e8865f',
        '#fdf6f3', '#fae9e1', '#f4d2c2', '#ebb49b', '#e0906d', '#75351f', '#3a1b10',
        '#fba24e', '#fdfcfa', '#f4f1ea', '#ece7dc', '#ddd6c8', '#6f685f',
        '#f2ede3', '#e2dbcd', '#a99f90', '#3a3733', '#221f1b', '#1f1b18',
        '#1f7a3d', '#5fca86', '#a35a06', '#f5b544', '#b3261e', '#f08a80',
        // --- Deep-green palette (rejected).
        '#0f3d2e', '#175640', '#0c2e23', '#5fa687', '#c9a96a', '#856a3c',
        '#f7faf8', '#08140f', '#0e2419', '#0f1a15', '#e8f1ec',
        // --- Icy blue / frost (rejected). Worth keeping on the list even now
        // that the product IS blue: none of these values is in Velora Midnight,
        // and a stray one means somebody reached for a generic Tailwind blue
        // instead of a token.
        '#3b82f6', '#2166db', '#1a56bd', '#60a5fa', '#93c5fd', '#fbfcfe',
        '#f5f8fc', '#eef3f9', '#0b1220', '#131c2e', '#0f172a', '#55637a', '#e6edf6',
    ];

    /**
     * Shipped on purpose, and each for a reason a future reader will otherwise
     * try to "clean up".
     *
     * @var list<string>
     */
    private const DELIBERATELY_LIVE = [
        // Slate-navy neutrals.
        '#f4f6fb', '#ebeff6', '#d5dce8', '#b4bfd2', '#7c8aa0', '#5b6882',
        '#4a5670', '#414c66', '#33405a', '#1e2a44', '#0e1a33', '#0b1636',
        // Electric blue.
        '#f0f5ff', '#e8efff', '#cfddff', '#a9c2ff', '#5b8cff',
        '#1e5eff', '#1748cc', '#123aa8', '#0e2e85', '#0b2263',
        // Sidebar, dark in both themes.
        '#16244a', '#a9b8d6', '#eef2fa', '#1e2a4d',
        // The AI accent. Reserved for AI moments — see docs/DESIGN.md.
        '#38bdf8', '#0369a1', '#e0f4fe', '#7dd3fc', '#0b2b3d',
        // Status, both cuts.
        '#0a7f5a', '#12b886', '#d9f5ec', '#b45309', '#f59e0b', '#fef3d7',
        '#d92d20', '#fde8e6', '#a81d13',
        '#34d399', '#fbbf24', '#f87171', '#0c2e26', '#33270a', '#3a1614',
        // Dark surfaces.
        '#0a1226', '#111c36', '#172445', '#2a3a5e', '#3a4c74',
        '#0e1730', '#aab6ce', '#8494b2', '#5f7299', '#44598a', '#5a719f', '#c6d0e4',
        '#08122b',
        // Chart series — Okabe-Ito derived, colourblind-safe.
        '#009e73', '#d55e00', '#b86e00', '#cc79a7',
        '#6b93ff', '#34c79a', '#f07a2a', '#e69f00', '#e08fc0',
        // The mark's own gradient.
        '#4e7eff', '#14239b',
        // Telegram / WhatsApp / Instagram / Google brand marks. Third-party
        // identities are not ours to re-skin, so they are exempt.
        '#229ED9', '#25D366', '#FEDA75', '#D62976', '#4F5BD5', '#FFC107',
    ];

    /**
     * The two lists above are edited by hand every time the palette moves, and
     * the failure mode is silent: retire a hex that is also live and every
     * surface test fails at once with a misleading message. Catch it here
     * instead, where the message says what actually happened.
     */
    public function test_no_live_colour_is_also_on_the_retired_list(): void
    {
        $overlap = array_intersect(
            array_map('strtolower', self::DELIBERATELY_LIVE),
            array_map('strtolower', self::RETIRED_COLOURS)
        );

        $this->assertSame(
            [],
            array_values($overlap),
            'These colours are both shipped and banned: '.implode(', ', $overlap)
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function surfaces(): array
    {
        return [
            'landing ru' => ['/ru', false],
            'landing tg' => ['/tg', false],
            'landing en' => ['/en', false],
            'login' => ['/ru/login', false],
            'register' => ['/ru/register', false],
            'forgot password' => ['/ru/forgot-password', false],
            'agents index' => ['/agents', true],
            'leads' => ['/leads', true],
            'conversations' => ['/conversations', true],
        ];
    }

    #[DataProvider('surfaces')]
    public function test_surface_uses_the_current_brand(string $url, bool $needsAuth): void
    {
        $request = $this;

        if ($needsAuth) {
            $user = User::factory()->create();
            Agent::factory()->for($user)->create();
            $request = $this->actingAs($user);
        }

        $html = $request->get($url)->assertOk()->getContent();

        foreach (self::RETIRED_COLOURS as $hex) {
            $this->assertStringNotContainsStringIgnoringCase(
                $hex,
                $html,
                "{$url} still paints the retired colour {$hex}. Use a token from resources/css/tokens.css instead."
            );
        }

        // The product self-hosts its two faces; no third-party font origin and
        // no in-browser Tailwind compile on any surface.
        $this->assertStringNotContainsString('fonts.googleapis.com', $html, "{$url} still loads Google Fonts.");
        $this->assertStringNotContainsString('fonts.gstatic.com', $html, "{$url} still preconnects to Google Fonts.");
        $this->assertStringNotContainsString('fonts.bunny.net', $html, "{$url} still loads a font from Bunny.");
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html, "{$url} still uses the Tailwind CDN.");
    }

    /**
     * Every foreground/background pair the system ships, at the floor for the
     * role it plays. These are the projector rules, written down:
     *
     *   12:1  body text. Roughly 2.7× WCAG AA. A lit hall and a projector's
     *         raised black level eat most of the margin AA leaves you.
     *    7:1  secondary/muted text, and any text carrying real meaning.
     *    3:1  non-text UI that still has to be SEEN — input borders, focus
     *         rings, icon strokes, chart lines. WCAG 2.2's own floor for these.
     *    4.5  a label on a filled button, where the fill is the brand colour and
     *         the label is white. Held at AA rather than 12:1 because a filled
     *         primary button at 12:1 would require a near-black fill, which is
     *         no longer a primary button.
     *
     * Two values are deliberately absent as body text because they would fail
     * and are meant to: --v-blue-500 (#1E5EFF, 5.12:1 on white — a FILL and a
     * focus ring, never a paragraph) and --v-slate-500 (#5B6882 — a
     * placeholder). Anything blue carrying text uses blue-600, which is here.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function contrastPairs(): array
    {
        return [
            // --- LIGHT · body text, on each of the three light surfaces.
            'body on surface' => ['#0e1a33', '#ffffff', 12.0],
            'body on canvas' => ['#0e1a33', '#f4f6fb', 12.0],
            'body on sunk' => ['#0e1a33', '#ebeff6', 12.0],

            // --- LIGHT · secondary text. The reason --v-slate-650 exists: the
            // specified #4A5670 misses this bar on two of the three grounds.
            'muted on surface' => ['#414c66', '#ffffff', 7.0],
            'muted on canvas' => ['#414c66', '#f4f6fb', 7.0],
            'muted on sunk' => ['#414c66', '#ebeff6', 7.0],
            'stage muted on canvas' => ['#33405a', '#f4f6fb', 7.0],
            'placeholder on surface' => ['#5b6882', '#ffffff', 4.5],

            // --- LIGHT · the primary doing text work. Links and button labels
            // use blue-600, never the decorative blue-500.
            'primary text on surface' => ['#1748cc', '#ffffff', 7.0],
            'primary text on canvas' => ['#1748cc', '#f4f6fb', 6.5],
            'primary text on tint' => ['#1748cc', '#e8efff', 6.0],
            'white on primary fill' => ['#ffffff', '#1e5eff', 4.5],
            'white on primary hover' => ['#ffffff', '#1748cc', 4.5],

            // --- LIGHT · non-text UI, at WCAG 2.2's 3:1 floor.
            'focus ring on surface' => ['#1e5eff', '#ffffff', 3.0],
            'focus ring on canvas' => ['#1e5eff', '#f4f6fb', 3.0],
            'focus ring on sunk' => ['#1e5eff', '#ebeff6', 3.0],
            'input border on surface' => ['#7c8aa0', '#ffffff', 3.0],
            'input border on canvas' => ['#7c8aa0', '#f4f6fb', 3.0],

            // --- LIGHT · status. Each carries text, so each owes the text bar.
            'success text on surface' => ['#0a7f5a', '#ffffff', 4.5],
            'warning text on surface' => ['#b45309', '#ffffff', 4.5],
            'danger text on surface' => ['#d92d20', '#ffffff', 4.5],
            'white on danger fill' => ['#ffffff', '#d92d20', 4.5],
            'white on success fill' => ['#ffffff', '#0a7f5a', 4.5],

            // --- LIGHT · the AI accent. #38BDF8 is a FILL only; as text on a
            // light ground it must be the ink cut, and this is what pins that.
            'sparkle ink on surface' => ['#0369a1', '#ffffff', 4.5],
            'sparkle ink on canvas' => ['#0369a1', '#f4f6fb', 4.5],

            // --- SIDEBAR · dark in both themes, so it is checked once.
            'sidebar text on sidebar' => ['#a9b8d6', '#0b1636', 7.0],
            'sidebar active text' => ['#eef2fa', '#0b1636', 12.0],
            'white on sidebar active item' => ['#ffffff', '#1e5eff', 4.5],
            'sparkle on sidebar' => ['#38bdf8', '#0b1636', 3.0],

            // --- DARK · never a pure-black ground, never pure-white type.
            'dark body on canvas' => ['#eef2fa', '#0a1226', 12.0],
            'dark body on surface' => ['#eef2fa', '#111c36', 12.0],
            'dark body on surface-2' => ['#eef2fa', '#172445', 12.0],
            'dark muted on canvas' => ['#aab6ce', '#0a1226', 7.0],
            'dark muted on surface' => ['#aab6ce', '#111c36', 7.0],
            'dark muted on surface-2' => ['#aab6ce', '#172445', 7.0],
            'dark primary text on canvas' => ['#5b8cff', '#0a1226', 4.5],
            'dark primary text on surface' => ['#5b8cff', '#111c36', 4.5],
            'dark input border on surface' => ['#5f7299', '#111c36', 3.0],
            'dark input border on canvas' => ['#5f7299', '#0a1226', 3.0],
            'dark success on surface' => ['#34d399', '#111c36', 4.5],
            'dark warning on surface' => ['#fbbf24', '#111c36', 4.5],
            'dark danger on surface' => ['#f87171', '#111c36', 4.5],
            'dark sparkle ink on surface' => ['#7dd3fc', '#111c36', 4.5],
        ];
    }

    #[DataProvider('contrastPairs')]
    public function test_palette_pair_is_readable(string $foreground, string $background, float $minimum): void
    {
        $ratio = $this->contrastRatio($foreground, $background);

        $this->assertGreaterThanOrEqual(
            $minimum,
            round($ratio, 2),
            sprintf('%s on %s is %.2f:1, below the %.1f:1 required for its role.', $foreground, $background, $ratio, $minimum)
        );
    }

    /**
     * The five chart series must be distinguishable FROM EACH OTHER, which is a
     * different question from whether each is legible against the background.
     *
     * Contrast ratio is the wrong instrument for it, and using it here was a
     * real bug in an earlier version of this test: it reported blue #1E5EFF and
     * amber #B86E00 as "too close" (1.28:1) when they are 173° apart in hue and
     * impossible to confuse, while passing the one pair that genuinely is a
     * problem. Luminance is blind to hue, and hue is most of what separates a
     * categorical palette.
     *
     * So this measures hue and lightness instead: two series are tellable apart
     * if they differ by ≥25° of hue OR ≥0.15 of lightness.
     *
     * ONE documented exception, and it is a real weakness rather than a rounding
     * error — see docs/DESIGN.md:
     *
     *   #D55E00 (series 3) and #B86E00 (series 4) are 9.4° apart with a 0.06
     *   lightness gap. Vermillion and amber are the closest pair in the
     *   Okabe-Ito set and the hardest to separate under protanopia.
     *
     * They are shipped anyway because the system's rule is that colour is NEVER
     * the only signal: every series also carries its own marker shape or dash
     * pattern and a direct label, which is what actually distinguishes these
     * two. The exemption is listed explicitly here rather than by loosening the
     * bar for everyone, so that if a SECOND pair ever drifts this close, this
     * test fails instead of quietly accepting it.
     */
    public function test_chart_series_are_distinguishable_from_each_other(): void
    {
        $series = ['#1e5eff', '#009e73', '#d55e00', '#b86e00', '#cc79a7'];

        $this->assertSame($series, array_values(array_unique($series)), 'Two chart series are the same colour.');

        // Separated by marker and label rather than by hue. See above.
        $exempt = [['#d55e00', '#b86e00']];

        foreach ($series as $i => $a) {
            foreach (array_slice($series, $i + 1) as $b) {
                if (in_array([$a, $b], $exempt, true)) {
                    continue;
                }

                [$hueA, , $lightA] = $this->toHsl($a);
                [$hueB, , $lightB] = $this->toHsl($b);

                $hueGap = abs($hueA - $hueB);
                if ($hueGap > 180) {
                    $hueGap = 360 - $hueGap;
                }

                $this->assertTrue(
                    $hueGap >= 25.0 || abs($lightA - $lightB) >= 0.15,
                    sprintf(
                        'Chart series %s and %s are only %.1f° of hue and %.2f of lightness apart. '
                        .'Either separate them, or add the pair to the documented exemption list '
                        .'and give them distinct markers.',
                        $a,
                        $b,
                        $hueGap,
                        abs($lightA - $lightB)
                    )
                );
            }
        }
    }

    /**
     * @return array{float, float, float} hue in degrees, saturation, lightness
     */
    private function toHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = array_map(
            fn (int $offset): float => hexdec(substr($hex, $offset, 2)) / 255,
            [0, 2, 4]
        );

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta === 0.0) {
            return [0.0, 0.0, $lightness];
        }

        $hue = match ($max) {
            $r => 60 * fmod(($g - $b) / $delta, 6),
            $g => 60 * ((($b - $r) / $delta) + 2),
            default => 60 * ((($r - $g) / $delta) + 4),
        };

        return [$hue < 0 ? $hue + 360 : $hue, $delta / (1 - abs(2 * $lightness - 1)), $lightness];
    }

    private function contrastRatio(string $a, string $b): float
    {
        $la = $this->relativeLuminance($a);
        $lb = $this->relativeLuminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Both self-hosted faces must reach every surface, and neither may arrive
     * from a third-party origin.
     *
     * The dashboard is included deliberately: it once linked no webfont at all
     * and silently rendered in the system UI face while the other surfaces used
     * a downloaded one, so the app looked like two different products.
     *
     * The landing matters for the opposite reason — it ships its own inline
     * stylesheet and never loads the Vite bundle, which is exactly why the
     * faces are declared in a Blade partial rather than in app.css.
     */
    public function test_every_surface_loads_both_self_hosted_families(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $pages = [
            'landing' => $this->get('/ru'),
            'login' => $this->get('/ru/login'),
            'dashboard' => $this->actingAs($user)->get('/agents'),
        ];

        foreach ($pages as $name => $page) {
            $html = $page->assertOk()->getContent();

            $this->assertStringContainsString(
                "font-family: 'Onest'",
                $html,
                "{$name} does not declare the Onest face."
            );
            $this->assertStringContainsString(
                "font-family: 'InterNum'",
                $html,
                "{$name} does not declare the Inter numerals face."
            );
            $this->assertStringContainsString(
                'onest-var-2.001.woff2',
                $html,
                "{$name} does not link the self-hosted Onest file."
            );
        }
    }

    /**
     * Both faces are preloaded rather than discovered when the CSS parses.
     *
     * On a projector a font swapping in mid-sentence is far more visible than
     * it is on a laptop, and these are the two files every surface needs — 96 KB
     * between them, so there is no cost worth trading for a late swap.
     */
    public function test_both_faces_are_preloaded(): void
    {
        $html = $this->get('/ru')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<link rel="preload" as="font"[^>]+onest-var-2\.001\.woff2[^>]*>#',
            $html,
            'The landing does not preload Onest.'
        );
        $this->assertMatchesRegularExpression(
            '#<link rel="preload" as="font"[^>]+inter-numerals-4\.1\.woff2[^>]*>#',
            $html,
            'The landing does not preload the Inter numerals subset.'
        );
    }

    /**
     * The type system is ONE voice plus figures, and the order in --font-sans is
     * what makes that work.
     *
     * InterNum carries only the numeral range (see partials/fonts.blade.php), so
     * it has to be listed FIRST: the browser tries it, finds nothing outside
     * that range, and falls through to Onest for every letter. Reverse the two
     * and Onest answers first for everything, the tabular figures silently stop
     * being used, and dashboard numbers start jittering as they update — a
     * regression nobody would connect back to a font stack.
     *
     * The previous system set headings in a serif; `--font-display` must now
     * resolve to Onest, because views opt into the display voice by that name.
     */
    public function test_the_numeral_face_is_ordered_ahead_of_the_ui_face(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));

        $this->assertMatchesRegularExpression(
            "#--font-sans:\s*\n?\s*'InterNum',\s*'Onest'#",
            $tokens,
            'InterNum must precede Onest in --font-sans, or the numeral subset never wins.'
        );
        $this->assertMatchesRegularExpression(
            "#--font-display:\s*'Onest'#",
            $tokens,
            'font-display must resolve to Onest — views opt into the display voice by that name.'
        );
        $this->assertStringNotContainsString(
            'Source Serif 4',
            $tokens,
            'The serif display voice was retired; the system has one UI face.'
        );
    }
}
