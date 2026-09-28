<?php

namespace Tests\Feature\Frontend;

use App\Http\Middleware\ServeBuiltAssetsToRemoteClients;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every page loads its CSS from one place — the compiled Vite bundle — and a
 * phone reaching the app through the tunnel is never pointed at the dev server
 * it cannot reach.
 */
class AssetDeliveryTest extends TestCase
{
    protected const TUNNEL = 'https://granular-showman-monopoly.ngrok-free.dev';

    protected string $hotFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Simulate `npm run dev` without touching the real public/hot.
        $this->hotFile = tempnam(sys_get_temp_dir(), 'vite-hot-');
        file_put_contents($this->hotFile, 'http://127.0.0.1:5173');
        Vite::useHotFile($this->hotFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->hotFile);

        parent::tearDown();
    }

    /** Run the middleware on a request to $url and report what Vite decided. */
    protected function viteModeFor(string $url): string
    {
        $response = (new ServeBuiltAssetsToRemoteClients)->handle(
            Request::create($url),
            fn () => new Response(Vite::isRunningHot() ? 'hot' : 'built'),
        );

        return $response->getContent();
    }

    public function test_a_request_through_the_tunnel_gets_the_built_bundle_while_the_dev_server_runs(): void
    {
        $this->assertSame('built', $this->viteModeFor(self::TUNNEL.'/ru/register'));
    }

    /** @return array<string, array{0: string}> */
    public static function loopbackHosts(): array
    {
        return ['localhost' => ['http://localhost:8000'], 'ipv4 loopback' => ['http://127.0.0.1:8000']];
    }

    #[DataProvider('loopbackHosts')]
    public function test_the_dev_machine_itself_keeps_hot_reload(string $origin): void
    {
        $this->assertSame('hot', $this->viteModeFor($origin.'/ru/register'));
    }

    /**
     * The audit, on the real page: /register over the tunnel, with the dev
     * server "running", carries exactly one app stylesheet, from /build/assets,
     * and nothing from a CDN or the dev server.
     */
    public function test_the_register_page_over_the_tunnel_links_only_the_built_bundle(): void
    {
        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('No production build — run `npm run build` first.');
        }

        $html = $this->get(self::TUNNEL.'/ru/register')->assertOk()->getContent();

        preg_match_all('/<link[^>]+rel="stylesheet"[^>]*href="([^"]+)"/', $html, $sheets);
        $appSheets = array_values(array_filter($sheets[1], fn ($href) => str_contains($href, '/build/assets/app-')));

        $this->assertCount(1, $appSheets, 'Exactly one compiled app stylesheet.');
        foreach ($sheets[1] as $href) {
            $this->assertStringContainsString('/build/assets/', $href, "Stylesheet not from the Vite build: {$href}");
        }
        $this->assertStringNotContainsString('5173', $html);
        $this->assertDoesNotMatchRegularExpression('#cdn\.|tailwindcss\.com|unpkg\.com|jsdelivr#i', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function pageShells(): array
    {
        return [
            // The Inertia shell, and the Blade layout still serving the pages
            // that have not migrated yet.
            'inertia shell' => ['app.blade.php'],
            'dashboard layout' => ['layouts/dashboard.blade.php'],
            'landing' => ['showcase.blade.php'],
        ];
    }

    /**
     * Every document shell: the mobile viewport, a colour-scheme declaration
     * (without it Samsung Internet's dark mode force-darkens the light theme,
     * repainting the palette on those phones only), and no CDN stylesheet.
     */
    #[DataProvider('pageShells')]
    public function test_every_page_shell_declares_viewport_and_colour_scheme_and_loads_no_cdn(string $view): void
    {
        $source = file_get_contents(resource_path('views/'.$view));

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1" />', $source);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark" />', $source);
        $this->assertDoesNotMatchRegularExpression('#cdn\.tailwindcss|tailwindcss\.com|<link[^>]+href="https?://#i', $source);
    }

    /** The UA must not force-darken the light theme; `only` is what says so. */
    public function test_the_light_theme_opts_out_of_forced_darkening(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/:root\s*\{\s*color-scheme:\s*light only;/', $css);
    }
}
