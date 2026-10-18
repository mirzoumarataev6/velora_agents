<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectChecklistItem;
use App\Models\ProjectBoard;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 8. Two of the four asks here (focus moving into the dialog
 * on open, and returning to the opening card on close) are pure client-side
 * behaviour — app.js's turbo:before-visit/turbo:load handlers and the
 * dialog's own x-init/trap() — and this suite has no JS runtime to execute
 * them against (see CLAUDE.md: no frontend framework, no JS test runner
 * here). What a Feature test CAN prove, and does below: the markup those
 * behaviours depend on is actually present and wired to the right
 * elements, and the two server-renderable asks — a unique accessible name
 * per checklist toggle, and the dialog's focus-trap/initial-focus
 * attributes existing on the right root — are correct. The reactive
 * behaviour itself was checked manually in a real browser; see the PHASE 2
 * final report.
 */
class ProjectTaskPanelAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function openTaskPanel(User $owner, ProjectBoard $board, Task $task): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner)->get(route('projects.boards.show', [$board, 'task' => $task->id]));
    }

    public function test_each_checklist_toggle_has_a_unique_name_naming_the_item_and_its_state(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'A11y', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Write the spec', 'position' => 1]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Ship it', 'position' => 2, 'completed_at' => now()]);

        $response = $this->openTaskPanel($owner, $board, $task)->assertOk();

        $response->assertSee('aria-label="'.__('projects.checklist_mark_done', ['title' => 'Write the spec']).'"', false);
        $response->assertSee('aria-label="'.__('projects.checklist_mark_open', ['title' => 'Ship it']).'"', false);
        // The two items' labels must differ from each other, not just from
        // the old shared generic one — a screen reader reading the list by
        // name alone must be able to tell which item is which.
        $this->assertNotSame(
            __('projects.checklist_mark_done', ['title' => 'Write the spec']),
            __('projects.checklist_mark_open', ['title' => 'Ship it']),
        );
    }

    public function test_the_dialog_moves_focus_in_on_open_and_traps_tab_within_itself(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'A11y', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $content = $this->openTaskPanel($owner, $board, $task)->assertOk()->getContent();

        // x-init moves focus onto the close button ($refs.closePanel) the
        // instant the dialog exists; trap() wraps Tab/Shift+Tab at the
        // dialog's own first/last focusable element, scoped to $root (this
        // div, backdrop link included) rather than the whole document —
        // both on the SAME element that owns the dialog's lifecycle.
        $this->assertMatchesRegularExpression('/x-init="\$nextTick\(\(\) => \$refs\.closePanel\.focus\(\)\)"/', $content);
        $this->assertStringContainsString('@keydown.tab="trap($event)"', $content);
        $this->assertStringContainsString('@keydown.escape.window="$refs.closePanel.click()"', $content);
        $this->assertStringContainsString('x-ref="closePanel"', $content);
    }

    public function test_the_opening_card_carries_the_id_and_preserve_scroll_hook_focus_restoration_needs(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'A11y', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Focus target']);

        $content = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('data-project-task-id="'.$task->id.'"', $content);
        // app.js's click handler finds the opening card via
        // `[data-project-task-id] a[data-preserve-scroll]` — both
        // attributes must sit on the same overlay link for that selector
        // to ever match.
        $this->assertMatchesRegularExpression(
            '/data-project-task-id="'.$task->id.'"[^>]*>\s*<a href="[^"]*task='.$task->id.'" data-preserve-scroll/s',
            $content
        );
    }
}
