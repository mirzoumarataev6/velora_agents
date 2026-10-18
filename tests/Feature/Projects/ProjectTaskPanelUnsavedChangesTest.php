<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 16: closing the task panel (Esc, X, backdrop) while the edit
 * form had unsaved changes discarded them silently. A confirm() prompt, not
 * autosave-on-blur — see the WHY comment at task-panel.blade.php's own
 * x-data for the reasoning (autosave would mean deciding what a PARTIAL
 * save means for the single 'updated' activity event and the assignee-
 * notification diff, both of which already depend on a full before/after
 * compare; confirm() is the smaller change, and the same pattern already
 * used elsewhere in this app for an "are you sure" prompt).
 *
 * The confirm()/dirty-tracking interaction itself has no PHPUnit runtime to
 * execute against (no frontend framework, no JS test runner — see
 * CLAUDE.md) and was checked manually in a real browser, see the PHASE 2
 * final report. This asserts the markup it's wired through.
 */
class ProjectTaskPanelUnsavedChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_close_links_and_edit_form_carry_the_dirty_check_bindings(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->getContent();

        // Both the backdrop and the X link run the same check — Esc clicks
        // the X ($refs.closePanel.click()), so it goes through it too.
        $this->assertSame(2, substr_count($html, '@click="confirmClose($event)"'));
        $this->assertStringContainsString('dirty: false,', $html);
        $this->assertStringContainsString('@input="dirty = true" @change="dirty = true"', $html);
        $this->assertStringContainsString(__('projects.unsaved_changes_confirm'), $html);
    }

    public function test_an_archived_or_read_only_task_renders_no_edit_form_so_nothing_can_ever_go_dirty(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id, 'archived_at' => now(),
        ]);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('@input="dirty = true" @change="dirty = true"', $html);
        // The close links are still there, still safe to click straight
        // through (confirmClose() is a no-op when dirty can never be true).
        $this->assertSame(2, substr_count($html, '@click="confirmClose($event)"'));
    }
}
