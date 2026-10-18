<?php

namespace Tests\Feature\Frontend;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 1 item 5: at 375px the sidebar covered the content after a hard
 * reload AND after a Turbo visit — the burger menu looked permanently open.
 *
 * Root cause, confirmed with a real headless browser (Playwright), not
 * guessed: Alpine's `start()` does NOT walk the whole document looking for
 * directives. It queries only the registered ROOT selectors (chiefly
 * `[x-data]`), keeps the ones that are not nested inside another root, and
 * calls `initTree()` on exactly those (alpinejs/src/lifecycle.js, `start()`).
 * `<aside id="app-sidebar">` and the mobile header's burger `<button>` carry
 * `:class`/`@click` directly, bound only to the global `$store.shell` — by
 * design (see dashboard.blade.php's own comment on why this is a store, not
 * local `x-data`: it has to survive Turbo without re-running). Neither
 * element, nor any ancestor up to `<html>`, had an `x-data` attribute, so
 * neither ever matched a root selector — their own directives were simply
 * never collected or evaluated, while `x-data` components NESTED inside them
 * (the task bell, the locale switcher) worked fine, because THEY matched the
 * root selector independently and Alpine walked from there. `data-turbo-
 * permanent` compounded it for the Turbo-visit case: a permanent element is
 * never removed and re-inserted across navigations, so the one unbound node
 * from the very first page load simply persisted, unbound, forever after.
 *
 * Confirmed with Playwright against a live dev server at a 375px viewport:
 * before the fix, `#app-sidebar`'s computed class list never gained
 * `-translate-x-full`/`translate-x-0` on a hard reload OR a Turbo visit, and
 * `getBoundingClientRect().x` stayed 0 (covering the content, full width).
 * Manually calling the PUBLIC `Alpine.initTree(el)` on the same node, in the
 * same page, applied the classes correctly — proving the directive itself
 * was always valid and the only thing missing was Alpine ever reaching it.
 *
 * Fix: `<body x-data>` in layouts/dashboard.blade.php. An empty, inherited
 * scope that doesn't shadow or feed into any nested `x-data` component (they
 * already have their own, more specific scope) — its only effect is making
 * `<body>` itself match the root selector, so Alpine's initial walk (and
 * every subsequent one) now reaches literally everything under it,
 * including `<aside>`/`<header>`'s own directives and the body's own
 * `@inbox-focus.window` listener, which had exactly the same bug.
 *
 * This suite renders server-side only — it cannot execute Alpine's JS or
 * observe reactivity (see CLAUDE.md: no frontend framework, no JS test
 * runner here). What it CAN prove, and does: that the fix (`x-data` on
 * `<body>`) is actually present in the shipped markup, and that it has not
 * silently regressed into a component-local `x-data` that would reintroduce
 * exactly the Turbo-survival problem the store pattern exists to avoid (see
 * that comment in dashboard.blade.php). The reactive behaviour itself was
 * verified manually in a real browser — see the PHASE 1 final report.
 */
class MobileSidebarAlpineBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_body_carries_a_bare_x_data_so_alpine_walks_the_whole_shell(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('agents.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<body\s+x-data\s/',
            $html,
            '<body> must carry a bare x-data (no expression) — this is what makes Alpine.start() '.
            'treat it as a root and walk into the sidebar/header, whose own :class/@click directives '.
            'rely only on the global $store.shell and have no x-data of their own.'
        );

        $this->assertStringContainsString('id="app-sidebar"', $html);
        $this->assertStringContainsString(':class="[', $html);
        $this->assertStringContainsString('$store.shell.mobileNavOpen', $html);
    }

    public function test_the_mobile_burger_and_scrim_still_toggle_the_same_store_property(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('agents.index'))->assertOk()->getContent();

        $this->assertStringContainsString('@click="$store.shell.mobileNavOpen = true"', $html);
        $this->assertStringContainsString('@click="$store.shell.mobileNavOpen = false"', $html);
    }
}
