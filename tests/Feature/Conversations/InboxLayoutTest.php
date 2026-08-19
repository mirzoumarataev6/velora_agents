<?php

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inbox's layout behaviour: the nav rail handshake, the single-row filter
 * chips, the resizable panes and the bubble whitespace fix.
 *
 * These are markup-level assertions on purpose — they pin the contract between
 * the page and the layout chrome (an event name, a CSS variable) that a silent
 * rename would otherwise break with no test noticing.
 */
class InboxLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        Agent::factory()->for($this->owner)->create();
    }

    protected function inbox()
    {
        return $this->actingAs($this->owner)->get(route('conversations.index'));
    }

    /**
     * The page and the layout talk through `inbox-focus`; if either side is
     * renamed the sidebar silently stops collapsing.
     */
    public function test_the_inbox_and_the_chrome_agree_on_the_rail_event(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        // Emitted by the page when a conversation opens/closes...
        $inbox->assertSee("CustomEvent('inbox-focus'", false);
        // ...and listened for by the sidebar in the layout.
        $inbox->assertSee('inbox-focus.window', false);
        $inbox->assertSee('railCollapsed', false);
    }

    public function test_opening_a_conversation_collapses_the_rail_and_closing_restores_it(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('this.setRail(true);', false);
        $inbox->assertSee('this.setRail(false);', false);
        // The rail is a desktop affordance; mobile already uses a drawer.
        $inbox->assertSee("railCollapsed ? 'lg:w-[4.75rem]' : 'lg:w-64'", false);
    }

    /**
     * Chips, search and both dates share ONE non-wrapping strip. The filters
     * used to occupy two bands; a second container reappearing here is exactly
     * the regression this pins.
     */
    public function test_every_filter_control_sits_on_one_row(): void
    {
        $inbox = $this->inbox();
        $content = $inbox->getContent();

        $inbox->assertOk();
        $this->assertSame(1, substr_count($content, 'data-filter-bar'));
        $inbox->assertSee('flex-nowrap', false);
        // Nothing inside the strip is allowed to wrap.
        $this->assertStringNotContainsString('flex-wrap', $this->filterBar($content));

        // The chips and both date inputs are inside that one strip.
        $bar = $this->filterBar($content);
        foreach (['all', 'unread', 'needs_human', 'has_lead', 'closed'] as $chip) {
            $this->assertStringContainsString('conversations.filters.'.$chip, $bar);
        }
        $this->assertSame(2, substr_count($bar, 'type="date"'));
        $this->assertStringContainsString('type="search"', $bar);
    }

    /** Narrow screens scroll the strip sideways rather than wrapping it. */
    public function test_the_filter_strip_scrolls_instead_of_wrapping(): void
    {
        $bar = $this->filterBar($this->inbox()->getContent());

        $this->assertStringContainsString('overflow-x-auto', $bar);
        // Only the search may flex; everything else holds its size.
        $this->assertStringContainsString('flex-1', $bar);
    }

    /**
     * The markup of the single filter strip, from its hook to the inbox grid
     * that follows it.
     */
    protected function filterBar(string $content): string
    {
        $start = strpos($content, 'data-filter-bar');
        $this->assertNotFalse($start, 'The filter strip is missing.');

        $end = strpos($content, '--list-w', $start);
        $this->assertNotFalse($end, 'The inbox grid should follow the filter strip.');

        return substr($content, $start, $end - $start);
    }

    public function test_the_panes_are_full_height_and_resizable(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        // Both panes share one tall height rather than a short fixed box.
        // The underscores matter: `calc(100vh-13.5rem)` is invalid CSS and
        // Tailwind emits no rule for it at all, which is exactly how the pane
        // ended up collapsing to its content height before this pass.
        $this->assertSame(2, substr_count($inbox->getContent(), 'h-[calc(100vh_-_11.5rem)]'));
        // Width comes from the variable the drag handle writes.
        $inbox->assertSee('--list-w', false);
        $inbox->assertSee('startResize(', false);
        $inbox->assertSee("localStorage.setItem('inbox:list-width'", false);
    }

    public function test_the_drag_handle_is_desktop_only_and_labelled(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('lg:cursor-col-resize', false);
        $inbox->assertSee(__('conversations.index.resize_handle'), false);
    }

    public function test_bubbles_render_through_the_whitespace_normaliser(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        // The bubble renders through highlight(), which normalises the text via
        // bubbleText() before marking the search term inside it.
        $inbox->assertSee('const text = this.bubbleText(content);', false);
        // Runs of blank lines were what produced the dead space inside a bubble.
        $inbox->assertSee('replace(/\n{3,}/g', false);
        // Reading text stays left-aligned and hugs its content, capped around
        // three quarters of the pane like any messenger.
        $inbox->assertSee('w-fit max-w-[min(75%,34rem)]', false);
        $inbox->assertSee('text-left text-sm leading-snug', false);
        // pre-LINE, not pre-WRAP: runs of spaces are collapsed rather than
        // rendered literally, which is what stretched the gaps inside a bubble.
        $inbox->assertSee('whitespace-pre-line', false);
        $inbox->assertDontSee('whitespace-pre-wrap', false);
        $inbox->assertSee('replace(/[ \t]{2,}/g', false);
    }

    /**
     * The newline-preserving rule must sit on the inner span, with no whitespace
     * between it and the bubble's own tags.
     *
     * A newline-preserving rule on the CONTAINER also preserves the newline and
     * indentation of the markup itself, which renders as a blank line above the
     * text — the gap that looked like uneven padding. Pretty-printing this
     * element would silently bring it back, so the adjacency is pinned here.
     */
    public function test_the_bubble_markup_introduces_no_stray_line_break(): void
    {
        $content = $this->inbox()->getContent();

        // The span opens immediately after the div's ">" and closes immediately
        // before "</div>" — no newline, no indentation, no text nodes.
        $this->assertStringContainsString(
            '><span class="whitespace-pre-line" x-html="highlight(m.content, filters.q)"></span></div>',
            $content
        );

        // And the container itself carries no whitespace-preserving class.
        $this->assertStringNotContainsString('whitespace-pre-line break-words', $content);
    }

    /**
     * Search is live at ~150ms and jumps to the matched message rather than
     * dropping the reader at the bottom of the transcript.
     */
    public function test_search_is_live_and_jumps_to_the_matched_message(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('@input.debounce.150ms="reload()"', false);
        $inbox->assertSee('locateMatches()', false);
        $inbox->assertSee('scrollToMatch()', false);
        $inbox->assertSee('autoOpenTopMatch()', false);
        // Messages are addressable so a match can be scrolled to.
        $inbox->assertSee(':data-message-id="m.id"', false);
        $inbox->assertSee("block: 'center'", false);
    }

    public function test_the_matched_term_is_highlighted_and_steppable(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('x-html="highlight(m.content, filters.q)"', false);
        $inbox->assertSee('<mark class=', false);
        // The active hit is ringed, and multiple hits can be stepped through.
        $inbox->assertSee('isActiveMatch(m.id)', false);
        $inbox->assertSee('nextMatch()', false);
        $inbox->assertSee('prevMatch()', false);
    }

    /**
     * Message content is attacker-controlled, so the highlighter must escape
     * every segment it emits rather than trusting the text.
     */
    public function test_highlighted_message_text_is_escaped(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('escapeHtml(', false);
        $inbox->assertSee("replace(/&/g, '&amp;')", false);
        // Each slice is escaped separately, not the whole string up front.
        $inbox->assertSee('this.escapeHtml(hit[0])', false);
    }

    public function test_clearing_the_search_restores_the_normal_view(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('clearSearch()', false);
        $inbox->assertSee(__('conversations.search.clear'), false);
    }

    /**
     * Exactly one × in the search field.
     *
     * type="search" draws a native cancel button of its own, which sat next to
     * ours as a second, differently-styled ×. Ours is the one that survives —
     * the native control only blanks the input, while clearSearch() also drops
     * the matches and the auto-open key.
     */
    public function test_the_search_field_has_a_single_clear_button(): void
    {
        $bar = $this->filterBar($this->inbox()->getContent());

        // The browser's own control is suppressed...
        $this->assertStringContainsString('[&::-webkit-search-cancel-button]:hidden', $bar);
        // ...leaving exactly one clear control, and it is ours.
        $this->assertSame(1, substr_count($bar, '@click="clearSearch()"'));
        $this->assertSame(1, substr_count($bar, __('conversations.search.clear')));
    }

    /**
     * On desktop both panes are visible, so the thread needs its own way to be
     * dismissed — otherwise the rail would stay narrow with no way back.
     */
    public function test_the_thread_can_be_closed_at_every_breakpoint(): void
    {
        $inbox = $this->inbox();

        $inbox->assertOk();
        $inbox->assertSee('@keydown.escape.window', false);
        // The close button is no longer hidden above the mobile breakpoint.
        $inbox->assertDontSee('dark:hover:bg-white/10 lg:hidden" aria-label', false);
    }
}
