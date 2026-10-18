<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 12: drag used to disable for the whole board the moment any
 * column passed 50 cards. The real hazard was only ever dropping into a
 * column's empty TAIL space while more pages of it are hidden — the server
 * resolves position against the column's true full sibling order, so a
 * null before_task_id there silently jumps the card to the column's actual
 * end rather than where it visually landed. Dropping onto a specific
 * visible card is exact no matter how many more exist beyond the page.
 * See BoardController's dragEnabled comment and board.blade.php's drop().
 */
class ProjectBoardDragPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_drag_stays_enabled_on_a_board_with_a_column_over_fifty_cards(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Busy', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->count(60)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk();

        $response->assertSee('draggable="true"', false);
        $response->assertSee(__('projects.drag_hint'));
    }

    public function test_drag_is_still_disabled_while_a_filter_or_search_is_active(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Findable']);

        $this->actingAs($owner);

        $filtered = $this->get(route('projects.boards.show', [$board, 'filter' => 'mine']))->assertOk();
        $filtered->assertDontSee('draggable="true"', false);
        $filtered->assertSee(__('projects.move_hint'));

        $searched = $this->get(route('projects.boards.show', [$board, 'q' => 'Findable']))->assertOk();
        $searched->assertDontSee('draggable="true"', false);
    }

    public function test_the_last_page_of_a_column_reports_no_more_pages(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Small', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->count(3)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk();

        $response->assertSee('data-has-more-pages="0"', false);
        $response->assertDontSee('data-has-more-pages="1"', false);
    }
}
