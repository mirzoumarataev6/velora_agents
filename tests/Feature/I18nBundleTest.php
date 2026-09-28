<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The instant-switch feature depends on every supported locale's strings being
 * embedded in the initial HTML for client-side swapping. These assert the
 * bundle is present and complete on each surface.
 */
class I18nBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_embeds_the_bundle_and_swaps_without_navigating(): void
    {
        $response = $this->get('/ru');

        $response->assertOk();
        // The switcher items stay real anchors — direct /ru /tg /en links must
        // keep working for SEO, for crawlers and with JS disabled...
        $response->assertSee('href="/en"', false);
        $response->assertSee('href="/tg"', false);
        // ...but they also opt into the client-side swap, so a click rewrites the
        // page in place instead of reloading it.
        $response->assertSee('data-locale="tg"', false);
        $response->assertSee('window.__i18n', false);

        // Every locale's landing strings ship with the first response; without
        // all three the instant swap would have nothing to swap to. Values are
        // \u-escaped in JSON, so assert on structure rather than Cyrillic text.
        $response->assertSee('"ru":{"landing":', false);
        $response->assertSee('"tg":{"landing":', false);
        $response->assertSee('"en":{"landing":', false);

        // The landing does NOT opt into the reload-after-swap path (that exists
        // for the dashboard's deep, untagged partials).
        $response->assertSee('reload: false', false);
    }

    public function test_landing_tags_its_content_for_in_place_swapping(): void
    {
        $response = $this->get('/en');

        $response->assertOk();
        // A representative sample across the page: hero, the repeated sections
        // (array index paths must resolve), and the footer.
        // The hero headline is typed by JS, so its translatable text hangs off
        // the aria-label rather than the element's own text content.
        // The hero headline is typed by JS, so its translatable text hangs off
        // the aria-label rather than the element's own text content.
        $response->assertSee('data-i18n-al="landing.hero.title_static"', false);
        $response->assertSee('data-i18n="landing.hero.sub"', false);
        $response->assertSee('data-i18n="landing.why.items.0.title"', false);
        $response->assertSee('data-i18n="landing.sources.inputs.0.name"', false);
        $response->assertSee('data-i18n="landing.how.steps.0.title"', false);
        $response->assertSee('data-i18n="landing.stats.items.0.label"', false);
        $response->assertSee('data-i18n="landing.cta.primary"', false);
        $response->assertSee('data-i18n="landing.faq.items.0.q"', false);
        $response->assertSee('data-i18n="landing.footer.tagline"', false);
    }

    public function test_landing_self_hosts_fonts_and_registers_the_service_worker(): void
    {
        $response = $this->get('/ru');

        $response->assertOk();
        // No third-party font origin and no in-browser Tailwind compile.
        $response->assertDontSee('fonts.googleapis.com', false);
        $response->assertDontSee('fonts.bunny.net', false);
        $response->assertDontSee('cdn.tailwindcss.com', false);
        // Repeat visits are served from the service worker cache.
        $response->assertSee("register('/sw.js')", false);
    }

    /**
     * The auth pages ship ONE locale, not three — and that is the improvement,
     * not a regression.
     *
     * They are Inertia now. Switching language is a visit that replaces the
     * `translations` prop and patches the component tree, so there is no reason
     * to embed every locale's strings in the document and no [data-i18n] walker
     * to feed. This asserts the replacement is actually in place: the current
     * locale's strings are present, and the other two are NOT — which is what
     * would silently creep back if someone re-added the Blade bundle partial to
     * the Inertia root template.
     *
     * The three-locale bundle remains correct for the Blade pages, and the
     * dashboard test below still pins it.
     */
    public function test_auth_pages_ship_only_the_active_locale(): void
    {
        $this->get('/en/login')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Auth/Login')
                    ->where('locale.current', 'en')
                    ->where('translations.auth.login.title', __('auth.login.title', [], 'en'))
                    ->where('locale.supported', ['ru', 'tg', 'en'])
            )
            // The retired machinery must not come back by way of the root template.
            ->assertDontSee('window.__i18n', false)
            ->assertDontSee('data-i18n=', false);
    }

    public function test_dashboard_embeds_the_bundle_and_tags_chrome(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        // A page that is still Blade — the bundle is the Blade mechanism.
        $response = $this->actingAs($user)->get('/conversations');

        $response->assertOk();
        $response->assertSee('window.__i18n', false);
        $response->assertSee('data-i18n="agents.nav.agents"', false);
        // The dashboard switcher opts into the client-side swap.
        $response->assertSee('data-locale="ru"', false);
        // Dashboard opts into the reload-after-swap path for deep content.
        $response->assertSee('reload: true', false);
    }
}
