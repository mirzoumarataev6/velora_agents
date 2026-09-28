<?php

namespace Tests\Feature\Frontend;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Inertia half of the product — the parts that would break silently.
 *
 * The migration runs page by page, so Blade and Vue are both live and the
 * contract BETWEEN them is what these pin: which pages are Inertia, which
 * bundle each side loads, and that the document shell still does the handful of
 * things that have to happen before either framework exists.
 */
class InertiaShellTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function inertiaAuthPages(): array
    {
        return [
            'login' => ['/ru/login', 'Auth/Login'],
            'register' => ['/ru/register', 'Auth/Register'],
            'forgot password' => ['/ru/forgot-password', 'Auth/ForgotPassword'],
        ];
    }

    #[DataProvider('inertiaAuthPages')]
    public function test_auth_pages_render_as_inertia_components(string $url, string $component): void
    {
        $this->get($url)
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component($component)
                    ->has('locale.current')
                    ->has('translations.auth')
                    // Ziggy, so a component can name a route instead of being
                    // handed a URL for every link.
                    ->has('ziggy.routes.login')
            );
    }

    public function test_the_reset_page_receives_its_token_and_email_as_props(): void
    {
        $this->get('/ru/reset-password/a-token?email=owner%40example.com')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Auth/ResetPassword')
                    ->where('token', 'a-token')
                    ->where('email', 'owner@example.com')
            );
    }

    /**
     * A failed submit has to come back through Inertia's `errors` prop.
     *
     * The auth forms are full Inertia submits since phase (d) — they could not
     * be before, because a successful sign-in redirects to the dashboard and an
     * XHR submit would have received that page's plain HTML while it was still
     * Blade. Either way the FAILURE path is this: back to the Inertia page with
     * the messages in props, rendering through exactly the same channel as the
     * client-side checks. If that stopped working, a wrong password would look
     * like nothing happening at all.
     */
    public function test_a_failed_sign_in_returns_errors_as_inertia_props(): void
    {
        $this->from('/ru/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->assertRedirect('/ru/login');

        $this->followingRedirects()
            ->from('/ru/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Auth/Login')
                    ->where('errors.email', __('auth.validation.login_failed'))
            );
    }

    /**
     * Each side of the migration loads its own bundle and only its own.
     *
     * An Inertia page pulling Alpine in, or a Blade page pulling Vue in, costs
     * every visitor a framework they never execute — and would go unnoticed,
     * because both pages still work.
     */
    public function test_inertia_pages_load_vue_and_blade_pages_load_alpine(): void
    {
        $inertia = $this->get('/ru/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/build/assets/inertia-[^"]+\.js#', $inertia);
        $this->assertDoesNotMatchRegularExpression('#/build/assets/app-[^"]+\.js#', $inertia);

        $user = User::factory()->create();
        Agent::factory()->for($user)->create();
        // The inbox is the Blade example while it is still Blade; when it
        // migrates, this moves to whatever is left — and when nothing is, the
        // whole assertion goes with app.js.
        $blade = $this->actingAs($user)->get(route('conversations.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/build/assets/app-[^"]+\.js#', $blade);
        $this->assertDoesNotMatchRegularExpression('#/build/assets/inertia-[^"]+\.js#', $blade);
    }

    /**
     * The Inertia document shell still does the three things that cannot wait
     * for Vue, because all three decide how the FIRST frame is painted:
     * the theme, stage mode, and the fonts.
     */
    public function test_the_inertia_shell_applies_theme_and_stage_before_first_paint(): void
    {
        $html = $this->get('/ru/login')->assertOk()->getContent();

        $this->assertStringContainsString("classList.toggle('dark'", $html);
        $this->assertStringContainsString("localStorage.getItem('mode') === 'stage'", $html);
        $this->assertStringContainsString('onest-var-2.001.woff2', $html);
        $this->assertStringContainsString('csrf-token', $html);
    }

    public function test_the_design_system_page_is_behind_auth_and_uses_the_app_shell(): void
    {
        $this->get('/design-system')->assertRedirect();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/design-system')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('DesignSystem')
                    // The shell reads all three to draw the topbar and rail.
                    ->where('auth.user.name', $user->name)
                    ->where('auth.user.initials', $user->initials())
                    ->has('ziggy.routes.logout')
            );
    }

    /**
     * Ziggy's route list must reach the client, and reach it BEFORE mount.
     *
     * THIS IS A REGRESSION GUARD FOR A BLANK SCREEN.
     *
     * `ZiggyVue.install(app, config)` takes the config as its SECOND argument.
     * Installed without one, Ziggy resolves per call from an explicit argument,
     * a bare `Ziggy` binding, then `globalThis.Ziggy` — and with all three
     * empty the first route() call threw "can't access property 'routes',
     * undefined". Components call route() during setup for a <Link href>, so
     * the throw happened inside mount and Vue rendered NOTHING. Every migrated
     * page was a blank screen.
     *
     * No PHP test could catch it: the server response was perfect, and the
     * failure was entirely in JavaScript nobody executed. What a PHP test CAN
     * pin is the two halves of the contract — the server shares the routes, and
     * the entry publishes them before mounting.
     */
    public function test_the_route_list_is_shared_and_published_before_mount(): void
    {
        $user = User::factory()->create();

        // The server's half: a real, non-empty route list on every page.
        $this->actingAs($user)
            ->get('/design-system')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $routes = $page->toArray()['props']['ziggy']['routes'];

                $this->assertNotEmpty($routes);
                $this->assertArrayHasKey('login', $routes);
                $this->assertArrayHasKey('agents.index', $routes);
            });

        // The client's half.
        $entry = file_get_contents(resource_path('js/inertia.js'));

        $this->assertStringContainsString('globalThis.Ziggy = page.props.ziggy', $entry);
        $this->assertStringContainsString('globalThis.route = ziggyRoute', $entry);

        // Published before mount, not after — setup() runs during mount, and
        // that is exactly where the failing call was.
        $this->assertLessThan(
            strpos($entry, 'app.mount(el)'),
            strpos($entry, 'publishRoutes(props.initialPage)'),
            'The route list must be published before the app mounts.',
        );

        // And refreshed per visit, so route().current() answers about the page
        // you are actually on rather than the one you booted on.
        $this->assertStringContainsString("router.on('success'", $entry);
        $this->assertStringContainsString("router.on('navigate'", $entry);
    }

    /**
     * Inertia sets `Vary: X-Inertia` by REPLACING the header rather than adding
     * to it, so running its middleware after the compression middleware wiped
     * the `Accept-Encoding` that had just been put there — which would let a
     * proxy hand a gzipped body to a client that never asked for one. The
     * middleware order in bootstrap/app.php is what prevents that, and this is
     * what would catch it being reordered.
     */
    public function test_both_vary_dimensions_survive_on_an_inertia_page(): void
    {
        $vary = $this->get('/ru/login', ['Accept-Encoding' => 'gzip'])
            ->assertOk()
            ->headers->get('Vary');

        $this->assertStringContainsString('X-Inertia', $vary);
        $this->assertStringContainsString('Accept-Encoding', $vary);
    }
}
