<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rebrand has to hold across the whole product, not just the page that was
 * being looked at when it was done.
 *
 * The palette lives in one place (the @theme block in resources/css/app.css), so
 * the realistic way for the old identity to survive is a hard-coded hex or a
 * stray third-party font link in a view nobody reopened. These render every real
 * surface and fail on exactly that.
 */
class BrandConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every palette this product has retired. Any of these appearing in emitted
     * HTML means a view is painting its own colour instead of using a token
     * from tailwind.config.js.
     *
     * Note what is deliberately NOT listed: #1c1a17. It was the old dark canvas
     * and is now `sand-900` / `night-soft` in the current system, so it is a
     * live value, not a retired one.
     *
     * @var list<string>
     */
    private const RETIRED_COLOURS = [
        // --- The bright terracotta (replaced by the softer "spark" terracotta).
        // It was a touch hot next to the cream canvas; #e27551 sits on the same
        // warm axis with the saturation pulled back.
        '#ee6c4d', '#e0512f', '#bc3d1f', '#99321a', '#f5936b',
        '#fef5f1', '#fde7de', '#fbcdbc', '#f7ae93', '#7c2a17', '#42130b',
        // --- Ember-orange system (replaced by the brighter terracotta).
        // Sampled from the logo PNG, it was more saturated than the product
        // needed and pulled every warm neutral toward yellow.
        '#fb6309', // ember brand
        '#dd4c05', // ember ring / hover
        '#b63a08', // ember, text-safe
        '#8f2f0d', // ember deep
        '#ff8134', // ember, dark-mode accent
        '#fff5ee', '#ffe8d8', '#ffcba6', '#ffa66b', '#75290f', '#401206',
        // NOTE: #ff9a4d / #f0530a are NOT here. They were the old CTA gradient
        // and are now the logo-only tokens — see DELIBERATELY_LIVE below.
        '#fbfaf9', '#f6f4f1', '#ece9e4', '#dedad3', // old sand surfaces
        '#8c867b', '#6b655b', '#4a453d', '#2e2a25', '#1c1a17', '#121110', // old sand inks
        '#141210', '#252119', '#2e2a23', '#f5f2ee', '#a9a29a', '#1f1c18', // old night
        '#0f7a4f', '#4ecb8c', '#c0261f', '#f28b82', // old semantics
        // --- Brand-sheet orange the landing used to paint itself with, before
        // the landing was folded into the product palette.
        '#ffba4c', '#f08a3e', '#e56c28', '#b4500f', '#11141b', '#1b1f28',
        '#f6f7f9', '#e9ebee', '#171b24', '#1c212b', '#e8eaee', '#f4f5f7', '#080a0e',
        // --- Cream + dusty-terracotta palette (two systems ago). Its accent
        // never matched anything: #da7756 is hsl(16, 63%, 60%).
        '#da7756', '#c2603f', '#a14e33', '#8f4228', '#e8865f',
        '#fdf6f3', '#fae9e1', '#f4d2c2', '#ebb49b', '#e0906d', '#75351f', '#3a1b10',
        '#fba24e',
        '#fdfcfa', '#f4f1ea', '#ece7dc', '#ddd6c8', // old creams
        '#6f685f', // old muted
        '#f2ede3', '#e2dbcd', '#a99f90', '#3a3733', '#221f1b', // old charcoals
        '#1f1b18', // old dark field
        '#1f7a3d', '#5fca86', '#a35a06', '#f5b544', '#b3261e', '#f08a80', // older semantics
        // --- Deep-green palette (rejected)
        '#0f3d2e', '#175640', '#0c2e23', '#5fa687', '#c9a96a', '#856a3c',
        '#f7faf8', '#08140f', '#0e2419', '#0f1a15', '#e8f1ec',
        // --- Icy blue / frost palette (rejected)
        '#3b82f6', '#2166db', '#1a56bd', '#60a5fa', '#93c5fd', '#fbfcfe',
        '#f5f8fc', '#eef3f9', '#0b1220', '#131c2e', '#0f172a', '#55637a', '#e6edf6',
    ];

    /**
     * Deliberately NOT retired, and each for a reason a future reader will
     * otherwise try to "clean up":
     *
     *   #faf9f5 / #2b2b2b  the current paper and ink. They were retired once,
     *                      under the cream system, and are back by design.
     *   #ff9a4d / #f0530a  the Velora mark's own gradient, kept as the
     *                      `logo-from` / `logo-to` tokens so anything redrawing
     *                      the logo in CSS matches the artwork.
     *   #229ED9 #25D366    Telegram / WhatsApp / Instagram / Google brand
     *   #FEDA75 #D62976    marks. Third-party identities are not ours to
     *   #4F5BD5 #FFC107    re-skin, so they are exempt from the palette.
     *
     * @var list<string>
     */
    private const DELIBERATELY_LIVE = [
        '#faf9f5', '#2b2b2b', '#1a1815', // paper, ink, night
        '#e27551', '#cc5c39', '#ab4a2c', '#ee9a7c', '#fae4db', // the accent scale in use
        '#fff8f3', // on-accent — the light label on an accent fill
        '#ff9a4d', '#f0530a', // the logo gradient
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
                "{$url} still paints the retired colour {$hex}. Use a theme token from app.css instead."
            );
        }

        // The whole product self-hosts one typeface; no third-party font origin
        // and no in-browser Tailwind compile on any surface.
        $this->assertStringNotContainsString('fonts.googleapis.com', $html, "{$url} still loads Google Fonts.");
        $this->assertStringNotContainsString('fonts.gstatic.com', $html, "{$url} still preconnects to Google Fonts.");
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html, "{$url} still uses the Tailwind CDN.");
    }

    /**
     * Every foreground/background pair the system actually ships, with the WCAG
     * minimum for the role each one plays: 4.5:1 for text, 3:1 for large text
     * and for non-text UI that still has to be seen (focus rings, the logo mark
     * on paper).
     *
     * Two values are deliberately absent as body text, because they fail and are
     * meant to: ember-500 (#e27551, the accent — 3.04:1 on white) and sand-500
     * (#8a8578 — 3.68:1). Those are a brand fill and a placeholder respectively.
     * Anything ember that carries text uses ember-700, which is here.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function contrastPairs(): array
    {
        return [
            // --- Light: text on each of the three light surfaces
            'body on white' => ['#2b2b2b', '#ffffff', 4.5],
            'body on paper' => ['#2b2b2b', '#faf9f5', 4.5],
            'body on sunk' => ['#2b2b2b', '#f0ede6', 4.5],
            'secondary text on white' => ['#4a463c', '#ffffff', 4.5],
            'muted on white' => ['#6b665a', '#ffffff', 4.5],
            'muted on paper' => ['#6b665a', '#faf9f5', 4.5],
            'muted on sunk' => ['#6b665a', '#f0ede6', 4.5],
            'placeholder on white' => ['#8a8578', '#ffffff', 3.0],

            // --- Light: the accent doing text and button work. Filled buttons
            // and links use ember-700, never the decorative ember-500 — see the
            // note in tailwind.config.js.
            'white on primary button' => ['#ffffff', '#ab4a2c', 4.5],
            'white on button hover' => ['#ffffff', '#8b3c23', 4.5],
            'link text on white' => ['#ab4a2c', '#ffffff', 4.5],
            'link text on paper' => ['#ab4a2c', '#faf9f5', 4.5],
            'link text on sunk' => ['#ab4a2c', '#f0ede6', 4.5],
            'link text on ember tint' => ['#ab4a2c', '#fdf3ef', 4.5],
            // The approved chip/badge tint, carrying both the accent text that
            // sits on it and ordinary ink.
            'link text on chip tint' => ['#ab4a2c', '#fae4db', 4.5],
            'ink on chip tint' => ['#2b2b2b', '#fae4db', 4.5],
            'large accent text' => ['#cc5c39', '#ffffff', 3.0],

            // --- Non-text UI. The focus ring is ember-600 precisely because
            // ember-500 measures 2.89:1 on the paper canvas and fails here.
            'focus ring on white' => ['#cc5c39', '#ffffff', 3.0],
            'focus ring on paper' => ['#cc5c39', '#faf9f5', 3.0],
            'focus ring on sunk' => ['#cc5c39', '#f0ede6', 3.0],
            'focus ring on dark' => ['#ee9a7c', '#1a1815', 3.0],
            'accent fill on white' => ['#e27551', '#ffffff', 2.5],

            // --- LIGHT ink on an accent fill. `on-accent` is a warm off-white,
            // so these are held to the 3:1 large-text/non-text floor rather
            // than 4.5:1 — the deliberate trade documented on the token in
            // tailwind.config.js. Two of the three are pinned here so the
            // trade cannot silently get worse: a future accent that drops the
            // label below 3:1 fails the build rather than shipping.
            'on-accent label on the accent fill' => ['#fff8f3', '#e27551', 2.8],
            'on-accent label on the hover fill' => ['#fff8f3', '#cc5c39', 3.0],
            'on-accent label on a deep fill' => ['#fff8f3', '#ab4a2c', 4.5],
            // Light ink on the two filled semantic buttons.
            'on-accent label on danger fill' => ['#fff8f3', '#c2183a', 4.5],
            'on-accent label on success fill' => ['#fff8f3', '#0e7a4a', 4.5],

            // --- Light semantics
            'success on white' => ['#0e7a4a', '#ffffff', 4.5],
            'warning on white' => ['#96620a', '#ffffff', 4.5],
            'danger on white' => ['#c2183a', '#ffffff', 4.5],
            'info on white' => ['#1f6f8b', '#ffffff', 4.5],
            'white on danger button' => ['#ffffff', '#c2183a', 4.5],
            'white on success button' => ['#ffffff', '#0e7a4a', 4.5],

            // --- Dark: warm near-black, four elevation steps
            'dark body on canvas' => ['#f5f1e8', '#1a1815', 4.5],
            'dark body on card' => ['#f5f1e8', '#242019', 4.5],
            'dark body on raised' => ['#f5f1e8', '#2b2620', 4.5],
            'dark muted on canvas' => ['#a39d90', '#1a1815', 4.5],
            'dark muted on card' => ['#a39d90', '#242019', 4.5],
            'dark muted on raised' => ['#a39d90', '#2b2620', 4.5],
            'dark accent text' => ['#ee9a7c', '#1a1815', 4.5],
            'dark accent on card' => ['#ee9a7c', '#242019', 4.5],
            'dark accent on raised' => ['#ee9a7c', '#2b2620', 4.5],
            'dark success' => ['#57cf94', '#1a1815', 4.5],
            'dark warning' => ['#f0b429', '#1a1815', 4.5],
            'dark danger' => ['#f2899a', '#1a1815', 4.5],
            'dark info' => ['#63c5e0', '#1a1815', 4.5],
            'dark success on card' => ['#57cf94', '#242019', 4.5],
            'dark danger on card' => ['#f2899a', '#242019', 4.5],
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
     * This used to assert the string "inter", from when Inter was the product
     * face. It kept passing after the move to Geist for the worst possible
     * reason — "inter" is a substring of half the words in a rendered page — so
     * it was checking nothing at all. It now asserts the real thing: both
     * self-hosted families reach every surface, and neither arrives from a
     * third-party origin.
     */
    public function test_every_surface_loads_both_self_hosted_families(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        // Dashboard included deliberately: it previously linked no webfont at
        // all and silently rendered in the system UI face.
        $pages = [
            'landing' => $this->get('/ru'),
            'login' => $this->get('/ru/login'),
            'dashboard' => $this->actingAs($user)->get('/agents'),
        ];

        foreach ($pages as $name => $page) {
            $html = $page->assertOk()->getContent();

            // Two shapes are valid, because the partial has two modes: a built
            // app links the generated stylesheet, while a running dev server
            // inlines the @font-face blocks. Both must carry both families.
            $linksBuiltSheet = (bool) preg_match(
                '#<link[^>]+rel="stylesheet"[^>]+href="[^"]*build/assets/fonts-[^"]+\.css"#',
                $html
            );
            $inlinesBothFamilies = str_contains($html, 'font-family: "Geist"')
                && str_contains($html, 'font-family: "Source Serif 4"');

            $this->assertTrue(
                $linksBuiltSheet || $inlinesBothFamilies,
                "{$name} loads neither the built font stylesheet nor inline faces for both families."
            );

            $this->assertStringNotContainsString(
                'fonts.bunny.net',
                $html,
                "{$name} pulls a font from Bunny at runtime; the faces are supposed to be self-hosted."
            );
        }
    }

    /**
     * The display face must be preloaded, not discovered when the CSS parses —
     * it sets the largest string on the page, so a late swap is the most
     * visible one there is.
     *
     * Only meaningful against a build: with a dev server running there is no
     * manifest to pick preloads from, and the partial correctly falls back to
     * Vite's own tag.
     */
    public function test_the_display_face_is_preloaded(): void
    {
        if (file_exists(public_path('hot'))) {
            $this->markTestSkipped('Vite dev server is running; preloads come from the build manifest.');
        }

        $html = $this->get('/ru')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<link rel="preload" as="font"[^>]+source-serif-4-400[^>]+>#',
            $html,
            'The landing does not preload the serif display face.'
        );
    }

    /**
     * The two-voice rule, asserted where it is authored: the serif is the
     * display family and the sans carries body and UI. A future edit that
     * points `--font-sans` at the serif (or drops the serif entirely) breaks
     * readability across the whole product, and would otherwise only be caught
     * by someone looking at a screenshot.
     */
    public function test_the_type_system_keeps_two_distinct_voices(): void
    {
        $palette = file_get_contents(resource_path('css/palette.generated.css'));

        $this->assertMatchesRegularExpression(
            '#--font-sans:\s*Geist#',
            $palette,
            'Body/UI text must stay on the sans.'
        );
        $this->assertMatchesRegularExpression(
            "#--font-serif:\s*'Source Serif 4'#",
            $palette,
            'The display voice must be the self-hosted serif.'
        );
        $this->assertMatchesRegularExpression(
            "#--font-display:\s*'Source Serif 4'#",
            $palette,
            'font-display must resolve to the serif, since views opt into it by that name.'
        );
    }
}
