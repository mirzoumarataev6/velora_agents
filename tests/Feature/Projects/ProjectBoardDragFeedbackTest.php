<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 15: dragging a card gave no feedback about where it would
 * land — no highlight on the column under the pointer, no indication of
 * which card it would be inserted before. This asserts the markup the
 * feedback is wired through (Alpine state + :class bindings, the CSS
 * classes/tokens they toggle); the actual drag interaction has no PHPUnit
 * runtime to execute against (no frontend framework, no JS test runner —
 * see CLAUDE.md) and was checked manually in a real browser, see the
 * PHASE 2 final report.
 */
class ProjectBoardDragFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_column_and_card_carries_the_drag_feedback_bindings(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $html = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('over($event, '.$column->id.')', $html);
        $this->assertStringContainsString('dragLeaveColumn($event, '.$column->id.')', $html);
        $this->assertStringContainsString('dragOverColumn === '.$column->id.' && \'project-kanban-column--drag-over\'', $html);
        $this->assertStringContainsString('dragOverCardId === \''.$task->id.'\' && \'project-task-card--drop-before\'', $html);
        // Resetting on dragend matters as much as setting on dragover: a
        // drag that ends outside any valid target (dropped on the page
        // background, cancelled with Esc) must not leave a stale highlight.
        $this->assertStringContainsString('dragOverColumn = null; dragOverCardId = null', $html);
    }

    public function test_the_drag_feedback_classes_use_brand_tokens_not_a_hardcoded_colour(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.project-kanban-column--drag-over', $css);
        $this->assertStringContainsString('.project-task-card--drop-before::before', $css);
        // Scoped to just the new rules, not the whole (much larger) file —
        // a literal #hex anywhere else in app.css is a pre-existing,
        // out-of-scope concern this test has no business asserting on.
        $start = strpos($css, '.project-kanban-column--drag-over');
        $end = strpos($css, '@media (prefers-reduced-motion: reduce) {
    .project-kanban-column { transition: none; }
}');
        $dragFeedbackCss = substr($css, $start, $end - $start);

        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $dragFeedbackCss);
        $this->assertStringContainsString('var(--color-brand)', $dragFeedbackCss);

        // Reduced-motion is respected for the column highlight's transition.
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
    }
}
