<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The main sidebar can be collapsed to an icon rail, and the choice sticks.
 *
 * REWRITTEN FOR THE VUE RAIL. The previous version asserted the Alpine
 * implementation literally — `toggleRail()`, `railCollapsed ? 'lg:w-[4.75rem]'
 * : 'lg:w-64'`, `:aria-expanded="(! railCollapsed).toString()"`. Every one of
 * those strings was the implementation rather than the behaviour, and none of
 * them exists now that the rail is Components/AppSidebar.vue.
 *
 * What survives is the contract, and it is worth keeping because two halves of
 * it live in different files and only agree by convention:
 *
 *   · the collapsed state is applied BEFORE first paint, from the document
 *     shell, so a collapsed rail does not flash open on every navigation
 *   · the component reads its initial state back from that class rather than
 *     from storage, so the two cannot disagree on the first frame
 *   · collapsing hides labels without removing their accessible names
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

    private function sidebar(): string
    {
        return file_get_contents(resource_path('js/Components/AppSidebar.vue'));
    }

    /**
     * The shell applies the saved state before Vue exists.
     *
     * This is the half that cannot be done in the component: everything the
     * rail changes is layout, so applying it after first paint means the rail
     * visibly snaps shut on every page load.
     */
    public function test_the_saved_state_is_applied_before_first_paint(): void
    {
        $html = $this->actingAs($this->user)->get(route('agents.index'))->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('sidebar:collapsed')", $html);
        $this->assertStringContainsString("classList.add('rail-collapsed')", $html);
    }

    /**
     * And the CSS that class drives has something to select.
     *
     * `.rail-collapsed [data-sidebar]` is what sizes the rail until Vue mounts.
     * If the attribute were dropped from the component the pre-paint would
     * silently stop working — the page would still render perfectly, and the
     * rail would just flash open every time.
     */
    public function test_the_pre_paint_css_hook_is_present_on_both_sides(): void
    {
        $this->assertStringContainsString('data-sidebar', $this->sidebar());
        $this->assertStringContainsString(
            '.rail-collapsed [data-sidebar]',
            file_get_contents(resource_path('css/app.css')),
        );
    }

    /** The component takes its first value from the class, not from storage. */
    public function test_the_component_reads_its_initial_state_from_the_pre_paint_class(): void
    {
        $this->assertStringContainsString(
            "classList.contains('rail-collapsed')",
            $this->sidebar(),
            'Re-reading localStorage here would let the class and the component disagree on frame one.',
        );

        $this->assertStringContainsString("localStorage.setItem('sidebar:collapsed'", $this->sidebar());
    }

    /**
     * Collapsing hides the labels, it does not remove them.
     *
     * `v-show`, not `v-if`: a collapsed rail is icons only, and an icon with no
     * accessible name is unusable with a screen reader. Every item also carries
     * an explicit aria-label for the same reason.
     */
    public function test_collapsing_keeps_the_accessible_names(): void
    {
        $source = $this->sidebar();

        $this->assertStringContainsString('v-show="!collapsed"', $source);
        $this->assertStringNotContainsString('v-if="!collapsed"', $source);
        $this->assertStringContainsString(':aria-label="$t(item.label)"', $source);
    }

    /** The toggle reports its state, and says what it will do next. */
    public function test_the_toggle_reports_its_state(): void
    {
        $source = $this->sidebar();

        $this->assertStringContainsString(':aria-expanded="!collapsed"', $source);
        $this->assertStringContainsString('agents.nav.expand_sidebar', $source);
        $this->assertStringContainsString('agents.nav.collapse_sidebar', $source);
    }
}
