<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 10: nothing stops a visitor typing ?q[]=x into the address
 * bar instead of a plain string. `trim((string) $request->query('q', ''))`
 * then ran `(string)` on an array — PHP raises "Array to string
 * conversion", which Laravel's error handler turns into a thrown
 * ErrorException, a bare 500 for what should just mean "no search term".
 * `filter` and the per-column pagination params (`column_{id}`) were
 * audited the same way: `filter[]=x` never crashed (`in_array($array, ...,
 * true)` is false, never a type error) and `column_{id}[]=x` never crashed
 * either (Laravel's own paginator casts the page value with `(int)`, which
 * accepts an array fine) — both still get the explicit is_string()/default
 * treatment below for the same reason `q` does, not because either was
 * observed to crash.
 */
class ProjectBoardMalformedQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_array_search_param_falls_back_to_no_search_instead_of_a_server_error(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Visible task']);

        $response = $this->actingAs($owner)->get(route('projects.boards.show', $board).'?q[]=x&q[]=y');

        $response->assertOk();
        $response->assertSee('Visible task');
    }

    public function test_an_array_filter_param_falls_back_to_the_all_filter(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Visible task']);

        $response = $this->actingAs($owner)->get(route('projects.boards.show', $board).'?filter[]=x&filter[]=y');

        $response->assertOk();
        $response->assertSee('Visible task');
    }

    public function test_an_array_column_page_param_falls_back_to_page_one(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Visible task']);

        $response = $this->actingAs($owner)
            ->get(route('projects.boards.show', $board).'?column_'.$column->id.'[]=x&column_'.$column->id.'[]=y');

        $response->assertOk();
        $response->assertSee('Visible task');
    }

    public function test_an_overlong_search_param_is_truncated_not_rejected(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');

        $response = $this->actingAs($owner)->get(route('projects.boards.show', $board).'?q='.str_repeat('a', 500));

        $response->assertOk();
    }
}
