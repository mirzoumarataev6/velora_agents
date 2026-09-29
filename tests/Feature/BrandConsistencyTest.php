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
 * The palette lives in one place (tailwind.config.js, compiled to the @theme
 * block in resources/css/palette.generated.css and to config/palette.php), so
 * the realistic way for an old identity to survive is a hard-coded hex or a
 * stray third-party font link in a view nobody reopened. These render every real
 * surface and fail on exactly that.
 *
 * CURRENT SYSTEM — "clear daylight", the Eskhata re-skin. A flat cool-blue
 * banking surface: azure-500 (#1a50fc) as the identity, azure-600 (#0052cc)
 * doing every job that has to survive a contrast check, a #f1f2f6 canvas, white
 * cards with no border and no shadow, and one typeface (Golos Text) carrying
 * headings, body and the big figures alike.
 *
 * The contrast floors here are HIGHER than WCAG AA and deliberately so: body
 * text is held to 7:1 and secondary text to 4.5:1, because this dashboard gets
 * shown on a projector. Three greys sampled from the reference screenshots do
 * not clear that and were darkened — see the ✎ notes in tailwind.config.js.
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
        '#f6f7f9', '#e9ebee', '#171b24', '#1c212b', '#e8eaee', '#f4f5f7',
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
        // NOTE: #080a0e was on this list and has been removed. It is slate-950
        // in the current system — a live value again, exactly like #faf9f5 was
        // when it came back under the terracotta scheme.

        // --- Terracotta / "warm daylight" (the scheme this one replaces).
        // A warm accent on warm paper. It was coherent; it was simply not the
        // reference. Everything below is now banned, the accent scale first:
        '#e27551', // ember-500, the old accent
        '#cc5c39', // ember-600, focus ring / hover
        '#ab4a2c', // ember-700, filled buttons and link text
        '#8b3c23', // ember-800, pressed
        '#ee9a7c', // ember-400, the old dark-mode accent
        '#fdf3ef', '#fae4db', '#f6cbba', '#f0b096', '#71321d', '#3d1a0f',
        '#fff8f3', // the warm off-white that labelled an accent fill
        '#ff9a4d', '#f0530a', // the ORANGE logo gradient — the mark is blue now
        // Warm sand neutrals.
        '#faf9f5', '#f0ede6', '#e9e5dc', '#ddd8cb', '#b5afa0',
        '#8a8578', '#6b665a', '#4a463c', '#332f28', '#2b2b2b',
        // Warm night.
        '#1a1815', '#201d18', '#242019', '#2b2620', '#f5f1e8', '#a39d90', '#221e18',
        // The one semantic that moved: info was a cool blue-teal that now sits
        // too close to the accent, so it was pulled further toward teal.
        '#1f6f8b',
    ];

    /**
     * Deliberately NOT retired, and each for a reason a future reader will
     * otherwise try to "clean up":
     *
     *   #0f1115 / #f1f2f6  the current ink and canvas, both sampled from the
     *                      reference screenshots.
     *   #080a0e            slate-950. It was banned under the rejected frost
     *                      palette and is a live token again — the same round
     *                      trip #faf9f5 made once before.
     *   #4b83fc / #1523a0  the Velora mark's own gradient, read off the vector
     *                      supplied artwork in resources/brand/logo-source.png and kept as
     *                      the `logo-from` / `logo-to` tokens so anything
     *                      redrawing the logo in CSS matches the artwork.
     *   #229ED9 #25D366    Telegram / WhatsApp / Instagram / Google brand
     *   #FEDA75 #D62976    marks. Third-party identities are not ours to
     *   #4F5BD5 #FFC107    re-skin, so they are exempt from the palette.
     *
     * @var list<string>
     */
    private const DELIBERATELY_LIVE = [
        '#0f1115', '#f1f2f6', '#0d1117', // ink, canvas, night
        '#080a0e', // slate-950 — banned under the frost palette, live again
        '#1a50fc', '#0052cc', '#00429f', '#7aa5ff', '#e6f1fc', '#d8e6f8', // the accent scale in use
        '#2f66c4', // the header band
        '#ffffff', // on-accent — white labels an accent fill now
        '#4b83fc', '#1523a0', // the logo gradient, from the blue vector master
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
     * Every foreground/background pair the system actually ships, with the
     * minimum required for the role each one plays.
     *
     * The floors here are STRICTER than WCAG AA, on purpose: this dashboard is
     * shown on a projector, so body text is held to 7:1 rather than 4.5:1 and
     * secondary text to 4.5:1 rather than 3:1. Non-text UI — chevrons, focus
     * rings, decorative fills — stays at WCAG 2.2's 3:1, since raising that
     * would mean a chevron darker than the reference has anywhere.
     *
     * Three values are deliberately absent as body text, because they fail that
     * floor and are meant to: `faint` (#7d8592, 3.72:1 — the chevron and
     * placeholder colour, never a sentence), the header band (#2f66c4, which
     * carries large text only), and azure-500 (#1a50fc, 5.84:1 — the identity
     * fill, which clears secondary but not body).
     *
     * What is NOT here, and why: the reference's own greys. #818181 secondary
     * (3.90:1), #bfc1c3 inactive nav (1.81:1) and #b7b7b8 chevrons (2.00:1) are
     * all below these floors, which is exactly why the palette darkened them.
     * Asserting the shipped values is what stops a future "make it match the
     * screenshots more closely" edit from quietly undoing that.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function contrastPairs(): array
    {
        return [
            // --- Light: body text on every surface it can land on. 7:1.
            'body on white' => ['#0f1115', '#ffffff', 7.0],
            'body on canvas' => ['#0f1115', '#f1f2f6', 7.0],
            'body on sunk' => ['#0f1115', '#e8ebf1', 7.0],
            // The accent card carries DARK text, not white — this is the pair
            // that makes "Идентификация" legible.
            'body on accent tint' => ['#0f1115', '#d8e6f8', 7.0],
            'body on icon-circle tint' => ['#0f1115', '#e6f1fc', 7.0],

            // --- Light: secondary text. 4.5:1.
            'secondary heading on white' => ['#3a4150', '#ffffff', 4.5],
            'muted on white' => ['#5c6470', '#ffffff', 4.5],
            'muted on canvas' => ['#5c6470', '#f1f2f6', 4.5],
            'muted on sunk' => ['#5c6470', '#e8ebf1', 4.5],
            // The sub-line inside an accent card. The tightest pair in the
            // system at 4.73:1, and pinned here so it cannot drift under.
            'muted on accent tint' => ['#5c6470', '#d8e6f8', 4.5],

            // --- Non-text UI: chevrons and placeholders. 3:1.
            'chevron on white' => ['#7d8592', '#ffffff', 3.0],
            'chevron on canvas' => ['#7d8592', '#f1f2f6', 3.0],

            // --- The accent doing button and link work. Filled buttons and
            // links use azure-600 (#0052cc), which is what the reference
            // samples on "Все продукты" and on every outline icon.
            'white on primary button' => ['#ffffff', '#0052cc', 4.5],
            'white on button hover' => ['#ffffff', '#00429f', 4.5],
            'link on white' => ['#0052cc', '#ffffff', 4.5],
            'link on canvas' => ['#0052cc', '#f1f2f6', 4.5],
            'link on sunk' => ['#0052cc', '#e8ebf1', 4.5],
            // The secondary button and the icon circle: blue on the two tints.
            'link on icon-circle tint' => ['#0052cc', '#e6f1fc', 4.5],
            'link on accent tint' => ['#0052cc', '#d8e6f8', 4.5],
            'link on hover wash' => ['#0052cc', '#eff5ff', 4.5],
            // The identity blue, where it is used as text at all.
            'identity accent on white' => ['#1a50fc', '#ffffff', 4.5],

            // --- The soft header band. This is the compromise pair: the
            // reference band (#6696de) gives white 3.01:1, which is why this
            // one is darker. Held to 4.5:1 rather than 7:1 because only large
            // text sits on it — see the note on `band` in tailwind.config.js.
            'white on the header band' => ['#ffffff', '#2f66c4', 4.5],

            // --- Non-text UI: the focus ring. azure-600 rather than the
            // identity azure-500, so it reads as deliberate on a canvas this
            // light.
            'focus ring on white' => ['#0052cc', '#ffffff', 3.0],
            'focus ring on canvas' => ['#0052cc', '#f1f2f6', 3.0],
            'focus ring on sunk' => ['#0052cc', '#e8ebf1', 3.0],
            'focus ring on dark' => ['#7aa5ff', '#0d1117', 3.0],

            // --- Light semantics
            'success on white' => ['#0e7a4a', '#ffffff', 4.5],
            'warning on white' => ['#96620a', '#ffffff', 4.5],
            'danger on white' => ['#c2183a', '#ffffff', 4.5],
            'info on white' => ['#0e6f8b', '#ffffff', 4.5],
            'white on danger button' => ['#ffffff', '#c2183a', 4.5],
            'white on success button' => ['#ffffff', '#0e7a4a', 4.5],

            // --- The tooltip, the one inverted surface in the system.
            'tooltip label on ink' => ['#ffffff', '#0f1115', 7.0],

            // --- Dark: cool near-black, four elevation steps. Body at 7:1
            // here too — the projector does not care which theme is on.
            'dark body on canvas' => ['#e8ecf5', '#0d1117', 7.0],
            'dark body on card' => ['#e8ecf5', '#151b26', 7.0],
            'dark body on raised' => ['#e8ecf5', '#1d2433', 7.0],
            'dark muted on canvas' => ['#9aa6bd', '#0d1117', 4.5],
            'dark muted on card' => ['#9aa6bd', '#151b26', 4.5],
            'dark muted on raised' => ['#9aa6bd', '#1d2433', 4.5],
            'dark accent on canvas' => ['#7aa5ff', '#0d1117', 4.5],
            'dark accent on card' => ['#7aa5ff', '#151b26', 4.5],
            'dark accent on raised' => ['#7aa5ff', '#1d2433', 4.5],
            'dark success' => ['#57cf94', '#0d1117', 4.5],
            'dark warning' => ['#f0b429', '#0d1117', 4.5],
            'dark danger' => ['#f2899a', '#0d1117', 4.5],
            'dark info' => ['#63c5e0', '#0d1117', 4.5],
            'dark success on card' => ['#57cf94', '#151b26', 4.5],
            'dark danger on card' => ['#f2899a', '#151b26', 4.5],
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
     * it was checking nothing at all. It now asserts the real thing: the
     * self-hosted face reaches every surface, and does not arrive from a
     * third-party origin.
     *
     * There is ONE family: Onest, vendored from the foundry's own release and
     * declared in partials/fonts.blade.php. Source Serif 4 and Golos Text are
     * both retired, and `serif`/`display` resolve to the same stack as `sans`.
     */
    public function test_every_surface_loads_the_self_hosted_family(): void
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

            // One shape now, on every page. The faces used to live in
            // app.css, which the landing does not load — it ships its own
            // stylesheet and no Tailwind — so the landing preloaded three
            // woff2 that no @font-face ever claimed and rendered the whole
            // page in a system fallback. They are declared inline in
            // partials/fonts.blade.php instead, which every layout includes.
            $this->assertStringContainsString(
                "font-family: 'Onest'",
                $html,
                "{$name} does not declare the product face; it will render in a system fallback."
            );

            $this->assertStringNotContainsString(
                'fonts.bunny.net',
                $html,
                "{$name} pulls a font from Bunny at runtime; the faces are supposed to be self-hosted."
            );
        }
    }

    /**
     * All three weights must be preloaded, not discovered when the CSS parses.
     * A browser does not fetch a @font-face's src until something matches it,
     * which is after layout — and the display weight sets the largest string
     * on the page, so a late swap there is the most visible one there is.
     *
     * Three files at fixed paths, so unlike the old build-manifest version
     * this needs no build and never has to be skipped.
     */
    public function test_every_shipped_weight_is_preloaded(): void
    {
        $html = $this->get('/ru')->assertOk()->getContent();

        foreach ([400, 500, 600] as $weight) {
            $this->assertMatchesRegularExpression(
                '#<link rel="preload" as="font"[^>]+onest-'.$weight.'\.woff2#',
                $html,
                "The landing does not preload the {$weight} weight of the product face."
            );
        }

        // The ceiling, asserted where it would break: no 700 file is shipped,
        // so a preload for one would 404 on every page load.
        $this->assertStringNotContainsString('onest-700', $html);
    }

    /**
     * The preload and the @font-face must name the same URL, version query
     * included. They did not always live in the same file, and a preload that
     * warms a URL the stylesheet never asks for costs a download twice over
     * while fixing nothing.
     */
    public function test_the_preloads_and_the_faces_agree_on_the_url(): void
    {
        $html = $this->get('/ru')->assertOk()->getContent();

        preg_match_all('#href="([^"]*onest-\d{3}\.woff2[^"]*)"#', $html, $preloaded);
        preg_match_all("#url\('([^']*onest-\d{3}\.woff2[^']*)'\)#", $html, $declared);

        $this->assertCount(3, $preloaded[1]);
        $this->assertCount(3, $declared[1]);
        $this->assertSame(
            array_values(array_unique($preloaded[1])),
            array_values(array_unique($declared[1])),
            'The preload links and the @font-face src URLs have drifted apart.'
        );
    }

    /**
     * The one-voice rule, asserted where it is authored.
     *
     * Headings, body, section titles and the KPI figures are one sans, with
     * hierarchy carried by size, tracking and colour. Reintroducing a second
     * family — a serif for the display range, say, which is what two previous
     * schemes did — is the single most visible departure from the look, and
     * would otherwise only be caught by someone comparing screenshots.
     */
    public function test_the_type_system_keeps_one_voice(): void
    {
        $palette = file_get_contents(resource_path('css/palette.generated.css'));

        foreach (['sans', 'serif', 'display'] as $role) {
            $this->assertMatchesRegularExpression(
                "#--font-{$role}:\s*Onest#",
                $palette,
                "--font-{$role} must resolve to the product face; the system ships one voice."
            );
        }

        $this->assertStringNotContainsString(
            'Golos Text',
            $palette,
            'Golos Text was retired with the flat Eskhata pass and must not come back.'
        );

        $this->assertStringNotContainsString(
            'Source Serif 4',
            $palette,
            'The serif display face was retired with the terracotta scheme and must not come back.'
        );
    }

    /**
     * The palette reaches PHP as well as CSS, and the two must agree.
     *
     * config/palette.php exists for the places a custom property cannot go —
     * `<meta name="theme-color">` above all, which paints the address bar on a
     * phone and takes a literal hex. That file had gone stale on a retired
     * accent twice under the previous schemes, both times silently.
     */
    public function test_the_php_palette_matches_the_css_palette(): void
    {
        $css = file_get_contents(resource_path('css/palette.generated.css'));
        $php = require config_path('palette.php');

        foreach (['paper', 'night', 'ink', 'brand', 'brand-strong'] as $token) {
            $this->assertArrayHasKey($token, $php, "config/palette.php is missing `{$token}`. Run `npm run palette`.");

            preg_match("/--color-{$token}:\s*(#[0-9a-f]{6});/", $css, $m);
            $this->assertNotEmpty($m, "palette.generated.css is missing `--color-{$token}`.");
            $this->assertSame(
                $m[1],
                $php[$token],
                "config/palette.php and palette.generated.css disagree on `{$token}`. Run `npm run palette`."
            );
        }
    }

    /**
     * ONE MARK, and it is the supplied artwork.
     *
     * Every icon in the product is generated from resources/brand/logo-source.png
     * by scripts/generate-brand-assets.php. It used to be generated from a
     * hand-traced SVG of that artwork instead, so the favicon, the PWA icons
     * and the social card were all a copy of a copy — close enough that
     * nobody spotted it, wrong enough that the sparkle sat off and the
     * gradient's dark end was several stops light.
     */
    public function test_the_brand_master_is_the_supplied_artwork(): void
    {
        $this->assertFileExists(
            resource_path('brand/logo-source.png'),
            'The brand master is missing; every generated icon derives from it.'
        );

        $generator = file_get_contents(base_path('scripts/generate-brand-assets.php'));
        $this->assertStringContainsString(
            'resources/brand/logo-source.png',
            $generator,
            'The asset generator no longer reads the supplied artwork as its master.'
        );

        // The retired trace must not come back as a second source of truth.
        foreach (['brand/velora-mark.svg', 'brand/velora-mark.png'] as $trace) {
            $this->assertFileDoesNotExist(
                resource_path($trace),
                'The hand-traced mark is retired; logo-source.png is the only master.'
            );
        }
    }

    /**
     * The generated mark must be a MATTE, not a ghost.
     *
     * The alpha in the supplied artwork has to be recovered, because it ships
     * flattened onto white. The obvious way to do that — inverting the
     * compositing equation, a = 1 − min(r,g,b)/bg — is wrong for this mark and
     * was shipped briefly: the equation is only valid when the opaque areas
     * are near-black, and this mark's light azure has a darkest channel around
     * 75, so the entire light half of the V came out about 70% TRANSPARENT.
     *
     * On white that is invisible, because un-blending and recompositing over
     * white is a round trip — which is exactly why it needs a test rather than
     * an eye. On the dark sidebar it is a pale ghost.
     *
     * So: the mark's interior must be genuinely opaque, only a thin
     * antialiased border may be partial, and nothing opaque may still be the
     * white ground that was supposed to be keyed out.
     */
    public function test_the_generated_mark_has_a_real_alpha_channel(): void
    {
        $path = public_path('images/brand/gen/mark.png');
        $this->assertFileExists($path, 'Run `php scripts/generate-brand-assets.php`.');

        $image = imagecreatefrompng($path);
        $this->assertInstanceOf(\GdImage::class, $image);

        $width = imagesx($image);
        $height = imagesy($image);
        $opaque = 0;
        $clear = 0;
        $partial = 0;
        $whiteButOpaque = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($image, $x, $y);
                // GD's alpha is 7-bit and INVERTED: 0 is solid, 127 invisible.
                $alpha = ($colour >> 24) & 0x7F;

                if ($alpha === 0) {
                    $opaque++;

                    if (min(($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF) > 235) {
                        $whiteButOpaque++;
                    }
                } elseif ($alpha === 127) {
                    $clear++;
                } else {
                    $partial++;
                }
            }
        }

        $total = $width * $height;

        // The V and its sparkle cover roughly a quarter of their bounding box.
        // The ghost put this at 0.5%.
        $this->assertGreaterThan(
            0.15 * $total,
            $opaque,
            'The mark is barely opaque anywhere — the alpha recovery has turned the artwork into a ghost.'
        );

        // A keyed matte leaves only an antialiased border partial. The ghost
        // put this at 37%.
        $this->assertLessThan(
            0.05 * $total,
            $partial,
            'Too much of the mark is semi-transparent to be an antialiased edge.'
        );

        $this->assertGreaterThan(0.4 * $total, $clear, 'The white ground was not keyed out.');

        $this->assertSame(
            0,
            $whiteButOpaque,
            'Opaque near-white pixels survive in the mark; the ground is being painted as artwork.'
        );
    }

    /**
     * The icon cache key must move whenever the artwork does, or an owner who
     * has ever loaded the site keeps the old mark in their tab indefinitely —
     * favicons are the most aggressively cached asset there is, and these
     * files keep their names across regenerations.
     */
    public function test_the_icon_version_matches_the_manifest(): void
    {
        $html = $this->get('/ru')->assertOk()->getContent();

        preg_match('#/favicon\.ico\?v=([^"]+)#', $html, $inPage);
        $this->assertNotEmpty($inPage, 'The favicon link carries no cache-busting version.');

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true);
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringContainsString(
                '?v='.$inPage[1],
                $icon['src'],
                'The PWA manifest icons and the favicon links disagree on the artwork version.'
            );
        }
    }
}
