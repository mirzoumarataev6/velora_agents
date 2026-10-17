<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
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
