<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 3 item 19: the assignees box sat inside its own max-h-32/
 * overflow-y-auto scroll region, nested inside the panel's own scroll
 * region — a scrollbar inside a scrollbar. Its dark-theme border used
 * dark:border-hairline, which resolves to the SAME light-mode-tuned grey
 * (there is no dark override for --color-hairline) rather than this file's
 * own established dark:border-white/10.
 */
class ProjectTaskPanelAssigneesStylingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_assignees_box_has_no_nested_scroll_region_and_uses_the_dark_token(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $html = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->getContent();

        $start = strpos($html, 'name="assignees_present"');
        $assigneesFieldset = substr($html, $start, 400);

        $this->assertStringNotContainsString('max-h-32', $assigneesFieldset);
        $this->assertStringNotContainsString('overflow-y-auto', $assigneesFieldset);
        $this->assertStringNotContainsString('dark:border-hairline', $assigneesFieldset);
        $this->assertStringContainsString('dark:border-white/10', $assigneesFieldset);
    }
}
