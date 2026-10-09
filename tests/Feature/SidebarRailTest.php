<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The main sidebar can be collapsed to an icon rail during normal use, and the
 * choice sticks across pages.
 *
 * The state has two inputs — the owner's toggle and the inbox asking for space
 * — so these pin the contract between them as well as the markup.
 */
class SidebarRailTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Agent::factory()->for($this->user)->create();
    }

    protected function page(string $route = 'agents.index')
    {
        return $this->actingAs($this->user)->get(route($route));
    }

    public function test_the_toggle_is_rendered_on_every_dashboard_page(): void
    {
        foreach (['agents.index', 'leads.index', 'conversations.index', 'settings.index'] as $route) {
            $response = $this->page($route);

            $response->assertOk();
            $response->assertSee('toggleRail()', false);
            $response->assertSee(__('agents.nav.collapse_sidebar'), false);
        }
    }

    public function test_the_toggle_is_keyboard_accessible_and_reports_its_state(): void
    {
        $response = $this->page();

        $response->assertOk();
        // A real <button>, so it is focusable and works on Enter/Space.
        $response->assertSee('type="button" @click="$store.shell.toggleRail()"', false);
        $response->assertSee(':aria-expanded="(! $store.shell.railCollapsed).toString()"', false);
        // Both labels ship, so the control renames itself in either state.
        $response->assertSee(__('agents.nav.expand_sidebar'), false);
    }

    /**
     * Collapsed is icon-only, not hidden: the icons stay, the labels go.
     */
    public function test_collapsing_hides_labels_but_keeps_icons_and_accessible_names(): void
    {
        $response = $this->page();

        $response->assertOk();
        $response->assertSee("railCollapsed ? 'lg:w-[4.75rem]' : 'lg:w-64'", false);
        $response->assertSee('railCollapsed && \'lg:hidden\'', false);
        // Every nav item carries an accessible name, so the icon-only rail is
        // still navigable once the label text is hidden.
        foreach (['agents', 'conversations', 'leads', 'settings'] as $item) {
            $response->assertSee('aria-label="'.__('agents.nav.'.$item).'"', false);
        }
    }

    /**
     * The names above are aria-label rather than title on purpose: `title`
     * renders a native tooltip, which in the expanded rail merely repeated the
     * label already sitting beside the cursor.
     */
    public function test_nav_items_do_not_render_hover_tooltips(): void
    {
        $response = $this->page();

        $response->assertOk();

        foreach (['agents', 'conversations', 'leads', 'settings', 'logout'] as $item) {
            $response->assertDontSee('title="'.__('agents.nav.'.$item).'"', false);
        }

        // The owner's own name sat on the profile link as a tooltip too.
        $response->assertDontSee('title="'.$this->user->name.'"', false);
    }

    /**
     * The one control that keeps its tooltip: it is icon-only in BOTH states,
     * so unlike the nav items there is no visible label for it to duplicate.
     */
    public function test_the_rail_toggle_keeps_its_tooltip(): void
    {
        $response = $this->page();

        $response->assertOk();
        $response->assertSee(':title="$store.shell.railCollapsed ?', false);
    }

    /**
     * Persistence is applied before first paint, or a collapsed rail would
     * flash open on every navigation.
     *
     * Only the class-application half is asserted here, in the rendered HTML:
     * it is the part that actually prevents the flash, and it is the part
     * that still runs inline, synchronously, before anything else. The other
     * half — Alpine's `shell` store reading that class back, and persisting
     * a later toggle — now lives in the compiled bundle (resources/js/app.js)
     * rather than inline Blade script, so it is no longer something a raw
     * HTML-contains assertion on this response can see; it is covered by
     * reading that file instead.
     */
    public function test_the_saved_state_is_applied_before_paint(): void
    {
        $response = $this->page();

        $response->assertOk();
        $response->assertSee("localStorage.getItem('sidebar:collapsed')", false);
        $response->assertSee("classList.add('rail-collapsed')", false);
    }

    /**
     * The inbox may collapse the rail while a conversation is open, but closing
     * it must return to the owner's preference rather than forcing it open.
     */
    public function test_the_inbox_cannot_override_a_pinned_preference(): void
    {
        $response = $this->page('conversations.index');

        $response->assertOk();
        $response->assertSee('$store.shell.railCollapsed = $event.detail.collapsed || $store.shell.railPinned', false);
    }

    /**
     * Mobile keeps the drawer: a 4.75rem off-canvas panel would be unusable.
     */
    public function test_the_rail_is_desktop_only(): void
    {
        $response = $this->page();

        $response->assertOk();
        // The toggle itself only appears from lg up. Asserted on the two
        // classes that carry that FACT — `hidden` plus `lg:flex` — rather than
        // on the whole class string: this previously pinned a hover colour
        // alongside them, so a re-skin that changed nothing about the breakpoint
        // failed the test and said nothing useful about why.
        $response->assertSee('class="hidden h-8 w-8', false);
        $response->assertSee('lg:flex"', false);
        // ...and every collapse rule is lg-prefixed.
        $response->assertDontSee("railCollapsed ? 'w-[4.75rem]'", false);
    }
}
