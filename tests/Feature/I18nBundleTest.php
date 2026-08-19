<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_auth_pages_embed_the_bundle_and_intercept_the_switcher(): void
    {
        $response = $this->get('/en/login');

        $response->assertOk();
        $response->assertSee('window.__i18n', false);
        // The switcher opts into the client-side swap.
        $response->assertSee('data-locale="ru"', false);
        // All locales' auth namespace embedded (keys are JSON, values are
        // \u-escaped, so assert on the structure rather than the Cyrillic text).
        $response->assertSee('"ru":{"auth":', false);
        $response->assertSee('"tg":{"auth":', false);
        $response->assertSee('"en":{"auth":', false);
    }

    public function test_dashboard_embeds_the_bundle_and_tags_chrome(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->get('/agents');

        $response->assertOk();
        $response->assertSee('window.__i18n', false);
        $response->assertSee('data-i18n="agents.nav.agents"', false);
        // The dashboard switcher opts into the client-side swap.
        $response->assertSee('data-locale="ru"', false);
        // Dashboard opts into the reload-after-swap path for deep content.
        $response->assertSee('reload: true', false);
    }
}
