<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectChecklistItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 3 item 18: the card meta row was 11px, a pixel under this app's own
 * 12px floor for body text everywhere else; checklist progress was text
 * only ("2/5"), no bar.
 */
class ProjectCardChecklistBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_meta_row_is_at_least_12px(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Meta row check']);

        $html = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        // Scoped to the card's own meta row — other parts of the shared
        // dashboard shell (e.g. the team switcher) legitimately use 11px
        // for unrelated UI this item never touched.
        $this->assertMatchesRegularExpression(
            '/mt-3 flex flex-wrap items-center gap-2 text-xs"/',
            $html,
        );
        $cardStart = strpos($html, 'Meta row check');
        $metaRow = substr($html, $cardStart, 600);
        $this->assertStringNotContainsString('text-[11px]', $metaRow);
        $this->assertStringNotContainsString('text-[10px]', $metaRow);
    }

    public function test_a_card_with_checklist_items_shows_a_progress_bar_and_the_x_of_y_count(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Done one', 'position' => 1, 'completed_at' => now()]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Open one', 'position' => 2]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Open two', 'position' => 3]);

        $html = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('1/3', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuemax="3"', $html);
        $this->assertStringContainsString('aria-valuenow="1"', $html);
        // 1/3 rounds to 33%.
        $this->assertStringContainsString('style="width: 33%"', $html);
    }

    public function test_a_card_with_no_checklist_items_shows_no_bar(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $html = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        $this->assertStringNotContainsString('role="progressbar"', $html);
    }
}
