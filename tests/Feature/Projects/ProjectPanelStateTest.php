<?php

namespace Tests\Feature\Projects;

use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRJ-005/PRJ-006: opening or closing a task card used to drop the board's
 * search box, filter and per-column page — every link toggling the panel
 * rebuilt its href from route() with only the board (and, when opening, the
 * task id), discarding the rest of the query string. Esc also forced a full
 * page reload via window.location, bypassing Turbo entirely while the X
 * button and backdrop got Turbo's fast visit. These assert on the rendered
 * HTML the fix is expected to produce, in the style of TurboNavigationTest.
 */
class ProjectPanelStateTest extends TestCase
{
    use RefreshDatabase;

    private function boardWithTask(User $owner): array
    {
        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $column->id,
            'title' => 'Chase the login bug',
        ]);

        return [$board, $task];
    }

    private function member(User $owner, string $role): User
    {
        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', $role)->sole()->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    /**
     * PRJ-004: back()'s redirect never carries a URL fragment (#board-settings
     * is never sent as part of a Referer header), so the panel's open state
     * now rides on ?settings=1 instead. The server decides it — never the
     * client's x-data alone — so this is asserted on the rendered attribute,
     * not just "the page didn't 500".
     */
    public function test_settings_query_parameter_opens_the_panel_for_a_user_who_can_manage(): void
    {
        $owner = User::factory()->create();
        [$board] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?settings=1')
            ->assertOk()->getContent();

        $this->assertStringContainsString('projectKanban({ settingsOpen: true })', $html);
    }

    public function test_plain_board_url_renders_the_panel_closed(): void
    {
        $owner = User::factory()->create();
        [$board] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board))
            ->assertOk()->getContent();

        $this->assertStringContainsString('projectKanban({ settingsOpen: false })', $html);
    }

    public function test_settings_query_parameter_is_ignored_for_an_operator(): void
    {
        $owner = User::factory()->create();
        [$board] = $this->boardWithTask($owner);
        $operator = $this->member($owner, 'operator');

        $html = $this->actingAs($operator)
            ->get(route('projects.boards.show', $board).'?settings=1')
            ->assertOk()->getContent();

        $this->assertStringContainsString('projectKanban({ settingsOpen: false })', $html);
        $this->assertStringNotContainsString('id="board-settings"', $html);
    }

    public function test_settings_query_parameter_is_ignored_for_a_viewer(): void
    {
        $owner = User::factory()->create();
        [$board] = $this->boardWithTask($owner);
        $viewer = $this->member($owner, 'viewer');

        $html = $this->actingAs($viewer)
            ->get(route('projects.boards.show', $board).'?settings=1')
            ->assertOk()->getContent();

        $this->assertStringContainsString('projectKanban({ settingsOpen: false })', $html);
        $this->assertStringNotContainsString('id="board-settings"', $html);
    }

    /**
     * $boardLink() (board.blade.php) backs the card overlay and the task
     * panel's close/backdrop links — none of them must carry `settings`
     * forward, or opening a card would leave the settings panel open behind
     * the task panel it's supposed to be replaced by.
     */
    public function test_card_and_close_links_never_carry_the_settings_parameter(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]).'&settings=1')
            ->assertOk()->getContent();

        $cardHref = $this->extractHref($html, 'class="absolute inset-0 rounded-xl');
        $backdropHref = $this->extractHref($html, 'class="absolute inset-0 bg-ink/60');
        $closeHref = $this->extractHref($html, 'x-ref="closePanel"', attributeFirst: true);

        foreach ([$cardHref, $backdropHref, $closeHref] as $href) {
            $this->assertStringNotContainsString('settings=', $href);
        }
    }

    public function test_card_link_keeps_the_query_string_and_adds_the_task_id(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?q=login&filter=all')
            ->assertOk()->getContent();

        $href = $this->extractHref($html, 'class="absolute inset-0 rounded-xl');
        $this->assertStringContainsString('task='.$task->id, $href);
        $this->assertStringContainsString('q=login', $href);
        $this->assertStringContainsString('filter=all', $href);
    }

    public function test_close_and_backdrop_links_keep_the_query_string_and_drop_the_task_id(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?q=login&filter=all&task='.$task->id)
            ->assertOk()->getContent();

        $backdropHref = $this->extractHref($html, 'class="absolute inset-0 bg-ink/60');
        $closeHref = $this->extractHref($html, 'x-ref="closePanel"', attributeFirst: true);

        foreach ([$backdropHref, $closeHref] as $href) {
            $this->assertStringContainsString('q=login', $href);
            $this->assertStringContainsString('filter=all', $href);
            $this->assertStringNotContainsString('task=', $href);
        }
    }

    public function test_per_column_page_parameter_survives_opening_and_closing_a_card(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);
        $pageParam = 'column_'.$board->columns()->first()->id.'_page';

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?'.$pageParam.'=2')
            ->assertOk()->getContent();
        $cardHref = $this->extractHref($html, 'class="absolute inset-0 rounded-xl');
        $this->assertStringContainsString($pageParam.'=2', $cardHref);

        $htmlWithPanel = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?'.$pageParam.'=2&task='.$task->id)
            ->assertOk()->getContent();
        $closeHref = $this->extractHref($htmlWithPanel, 'x-ref="closePanel"', attributeFirst: true);
        $this->assertStringContainsString($pageParam.'=2', $closeHref);
    }

    public function test_escape_clicks_the_close_link_instead_of_forcing_a_full_reload(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->getContent();

        // Scoped to the task-panel partial itself: the dashboard layout's own
        // scripts (welcome modal, i18n bundle) legitimately use
        // window.location elsewhere on the same page for unrelated reasons.
        $panelStart = strpos($html, '<div class="fixed inset-0 z-[80]');
        $this->assertNotFalse($panelStart, 'Could not find the task panel in the response');
        $scriptStart = strpos($html, '<script>', $panelStart);
        $panelHtml = substr($html, $panelStart, $scriptStart - $panelStart);

        $this->assertStringNotContainsString('window.location', $panelHtml);
        $this->assertStringContainsString('@keydown.escape.window="$refs.closePanel.click()"', $panelHtml);
    }

    /**
     * PRJ-005 follow-up: a real browser PATCH carries _token and _method in
     * its body (the form uses @csrf @method('PATCH') under a plain POST verb
     * Laravel then spoofs). The redirect was built from $request->except('task'),
     * which reads all() — query string AND body — so the live CSRF token
     * ended up in the redirect URL, browser history and Referer header. The
     * original version of this test sent no body and never caught it.
     */
    public function test_archive_redirect_keeps_the_query_string_and_never_leaks_the_request_body(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $response = $this->actingAs($owner)->patch(
            route('projects.tasks.archive', [$board, $task]).'?q=login&filter=all',
            ['_token' => 'leaked-csrf-token-should-not-appear', '_method' => 'PATCH']
        );

        $response->assertRedirect(route('projects.boards.show', $board).'?q=login&filter=all');
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('_token', $location);
        $this->assertStringNotContainsString('_method', $location);
        $this->assertStringNotContainsString('leaked-csrf-token-should-not-appear', $location);
    }

    /**
     * PRJ-005 follow-up: array_merge(['board' => $board], <query>, ...) let a
     * query parameter named "board" overwrite the route parameter, so
     * ?board=999999 on the URL redirected every card link to a different
     * board. The route parameter must always win.
     */
    public function test_a_stray_board_query_parameter_cannot_redirect_the_card_link_to_another_board(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);
        // The search filter is a plain title LIKE; "x" has to actually be in
        // the title for the card to survive ?q=x and render at all.
        $task->forceFill(['title' => 'Fix the flux capacitor'])->save();

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?board=999999&q=x')
            ->assertOk()->getContent();

        $href = $this->extractHref($html, 'class="absolute inset-0 rounded-xl');
        $this->assertStringContainsString('/boards/'.$board->id.'?', $href);
        $this->assertStringContainsString('q=x', $href);
        $this->assertStringContainsString('task='.$task->id, $href);
        $this->assertStringNotContainsString('/boards/999999', $href);
    }

    /**
     * The scroll-preserve fix in app.js only acts on links carrying
     * data-preserve-scroll: the card overlay, the panel's X and its
     * backdrop (Esc already clicks the X, so it needs no attribute of its
     * own). Everything else — pagination, filters, a link to another board —
     * must keep landing at the top of a normal Turbo visit.
     */
    public function test_the_three_panel_toggling_links_carry_the_preserve_scroll_attribute(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->getContent();

        $cardTag = $this->extractTag($html, 'class="absolute inset-0 rounded-xl');
        $this->assertStringContainsString('data-preserve-scroll', $cardTag);

        $backdropTag = $this->extractTag($html, 'class="absolute inset-0 bg-ink/60');
        $this->assertStringContainsString('data-preserve-scroll', $backdropTag);

        $closeTag = $this->extractTag($html, 'x-ref="closePanel"');
        $this->assertStringContainsString('data-preserve-scroll', $closeTag);
    }

    public function test_pagination_and_filter_links_do_not_carry_the_preserve_scroll_attribute(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);
        $column = $board->columns()->first();
        // CARDS_PER_COLUMN is 50 (BoardController) — one over it is enough to
        // render a "next page" link for this column. filter=soon (not "mine",
        // which filters by assignee, not creator) still matches every one of
        // these: TaskFactory's default due_at is +1 day, inside the window.
        Task::factory()->for($owner)->count(51)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $column->id,
        ]);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?filter=soon')
            ->assertOk()->getContent();

        $nextPageTag = $this->extractTag($html, __('projects.next'));
        $this->assertStringNotContainsString('data-preserve-scroll', $nextPageTag);

        $clearFiltersTag = $this->extractTag($html, __('projects.clear_filters'));
        $this->assertStringNotContainsString('data-preserve-scroll', $clearFiltersTag);
    }

    public function test_board_root_carries_the_scroll_key(): void
    {
        $owner = User::factory()->create();
        [$board] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-scroll-key="board-'.$board->id.'"', $html);
    }

    public function test_archive_form_action_carries_the_current_query_string(): void
    {
        $owner = User::factory()->create();
        [$board, $task] = $this->boardWithTask($owner);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]).'&q=login&filter=all')
            ->assertOk()->getContent();

        $archiveHref = $this->extractHref($html, 'btn btn-ghost btn-sm text-danger', formAction: true);
        $this->assertStringContainsString('q=login', $archiveHref);
        $this->assertStringContainsString('filter=all', $archiveHref);
        $this->assertStringNotContainsString('task=', $archiveHref);
    }

    /**
     * Returns the full opening <a ...> tag nearest before a marker — an
     * attribute fragment inside the tag, or text content just after it —
     * so a test can assert on any attribute of the tag, not just href.
     */
    private function extractTag(string $html, string $needle): string
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "Could not find marker [{$needle}] in response HTML");

        $tagStart = strrpos(substr($html, 0, $pos), '<a ');
        $this->assertNotFalse($tagStart, 'Could not find enclosing <a> for marker');
        $tagEnd = strpos($html, '>', $tagStart);
        $this->assertNotFalse($tagEnd, 'Could not find end of <a> tag');

        return substr($html, $tagStart, $tagEnd - $tagStart + 1);
    }

    /**
     * Finds the href (or, with formAction, the enclosing <form>'s action) on
     * the element identified by a distinctive attribute/class fragment, and
     * decodes HTML entities so ?q=login&amp;filter=all reads as plain text.
     */
    private function extractHref(string $html, string $needle, bool $attributeFirst = false, bool $formAction = false): string
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "Could not find marker [{$needle}] in response HTML");

        if ($formAction) {
            $formStart = strrpos(substr($html, 0, $pos), '<form');
            $this->assertNotFalse($formStart, 'Could not find enclosing <form> for marker');
            preg_match('/action="([^"]+)"/', substr($html, $formStart, $pos - $formStart + 50), $matches);
        } elseif ($attributeFirst) {
            preg_match('/href="([^"]+)"/', substr($html, $pos, 200), $matches);
        } else {
            $tagStart = strrpos(substr($html, 0, $pos), '<a ');
            $this->assertNotFalse($tagStart, 'Could not find enclosing <a> for marker');
            preg_match('/href="([^"]+)"/', substr($html, $tagStart, $pos - $tagStart + 50), $matches);
        }

        $this->assertNotEmpty($matches[1] ?? null, "Could not find href near marker [{$needle}]");

        return html_entity_decode($matches[1]);
    }
}
