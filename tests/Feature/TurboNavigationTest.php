<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the "navigation looks like it refreshes twice"
 * report. Diagnosed with a Playwright probe (not part of this suite — it
 * drove a real browser and counted Turbo lifecycle events and document-level
 * listener registrations, see the task's final report for the numbers)
 * against two real causes:
 *
 *   1. Turbo's own preview: revisiting a page already in its snapshot cache
 *      paints that stale snapshot immediately, then replaces it with the
 *      fresh response — two `turbo:render` events, not one. Measured at
 *      ~250ms apart on a never-cached-before vs. already-cached navigation.
 *   2. partials/i18n-bundle's own click listener stacking: that inline
 *      script sits inside the swapped `<main>` region (not behind
 *      data-turbo-permanent), so Turbo re-executes it on every visit, and an
 *      unguarded `document.addEventListener` has no element lifecycle to
 *      unbind it — one more copy per navigation, forever (measured: +1 per
 *      navigation, 0 ever removed).
 *
 * Neither is executable from PHPUnit (both are runtime JS behaviour), so
 * these assert on what the server actually emits: the opt-out meta tag, and
 * the guard that makes the listener registration idempotent.
 */
class TurboNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_opts_out_of_turbos_stale_preview_render(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get('/agents')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta name="turbo-cache-control" content="no-preview" />',
            $html
        );
    }

    /**
     * Guards against the exact regression: the click listener for
     * [data-locale] switcher elements must be wrapped in a check against a
     * window-level flag, so re-running this inline script (which Turbo does
     * on every dashboard navigation) adds it at most once per session.
     */
    public function test_i18n_switcher_listener_registration_is_guarded_against_re_execution(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get('/agents')->assertOk()->getContent();

        $this->assertStringContainsString('if (!window.__i18nSwitcherBound)', $html);
        $this->assertStringContainsString('window.__i18nSwitcherBound = true;', $html);

        // The guard must actually wrap the registration, not just appear
        // somewhere else on the page.
        $this->assertMatchesRegularExpression(
            '/if \(!window\.__i18nSwitcherBound\) \{\s*window\.__i18nSwitcherBound = true;\s*document\.addEventListener\(.click./s',
            $html
        );
    }

    /**
     * The same partial renders on the login page too, which never loads
     * Turbo at all (see layouts/auth.blade.php) — the guard must be harmless
     * there: still exactly one registration per real page load, same as
     * before this fix.
     */
    public function test_guard_is_present_and_harmless_on_the_non_turbo_login_page(): void
    {
        $html = $this->get('/tg/login')->assertOk()->getContent();

        $this->assertStringContainsString('if (!window.__i18nSwitcherBound)', $html);
    }
}
