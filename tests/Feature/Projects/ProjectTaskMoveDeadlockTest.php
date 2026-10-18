<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectBoard;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use App\Services\Projects\ProjectTaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PHASE 1 item 1: two moves that swap cards between the same pair of columns
 * deadlock under MySQL — move A locks "task in X" then wants column Y's
 * sibling list (which holds the task move B already locked), while move B
 * locks "task in Y" then wants column X's sibling list (which holds the task
 * move A already locked). A cycle, which MySQL resolves by killing one
 * transaction with SQLSTATE 40001 (error 1213) — the 500 the task describes.
 *
 * This suite runs on sqlite in-memory (phpunit.xml), which cannot reproduce a
 * genuine cross-connection InnoDB deadlock — there is only one connection,
 * and SQLite's locking model doesn't have 1213/40001 at all. Two things
 * stand in, matching the precedent in TaskWipLimitOccupancyLockTest:
 *
 * 1. A source-level check that move() locks the ProjectBoard row before any
 *    other lock, and that the transaction carries a retry count — this is
 *    what would fail if someone reordered the locks back to the deadlocking
 *    shape, which no functional test on sqlite could ever catch.
 * 2. A functional swap: two sequential moves that put each other's task in
 *    the way (A's target column holds the task B is about to move away,
 *    and vice versa) complete and return successfully — proving the new
 *    board-row lock didn't break the ordinary case it now serialises.
 */
class ProjectTaskMoveDeadlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_locks_the_board_row_before_the_task_and_column_locks_with_a_retry(): void
    {
        $method = new ReflectionMethod(ProjectTaskWorkflow::class, 'move');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $boardLockPos = strpos($body, 'ProjectBoard::whereKey');
        $taskLockPos = strpos($body, 'Task::whereKey');
        $columnLockPos = strpos($body, 'ProjectColumn::whereKey');

        $this->assertNotFalse($boardLockPos, 'move() must lock the ProjectBoard row (deadlock fix).');
        $this->assertNotFalse($taskLockPos);
        $this->assertNotFalse($columnLockPos);
        $this->assertTrue(
            $boardLockPos < $taskLockPos && $taskLockPos < $columnLockPos,
            'The board row must be locked first, before the task and column locks — '.
            'otherwise two moves can still lock those two in reverse order and deadlock.'
        );

        $this->assertMatchesRegularExpression(
            '/DB::transaction\(function.*\},\s*3\);/s',
            $body,
            'move() must retry its transaction (MySQL kills one side of a deadlock; the retry replays it).'
        );
    }

    public function test_swapping_two_cards_between_the_same_pair_of_columns_both_succeed(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Swap', null, 'team');
        [$columnX, $columnY] = $board->columns()->orderBy('position')->take(2)->get();

        $taskInX = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $columnX->id, 'title' => 'In X',
        ]);
        $taskInY = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $columnY->id, 'title' => 'In Y',
        ]);

        $this->actingAs($owner);

        // Each move's target column holds the other task — the exact shape
        // that deadlocks under real MySQL connections. On one sqlite
        // connection this exercises the same lock-acquisition order without
        // triggering the race; it is the source check above that proves the
        // order is correct.
        $this->patch(route('projects.tasks.move', [$board, $taskInX]), ['column_id' => $columnY->id])
            ->assertRedirect();
        $this->patch(route('projects.tasks.move', [$board, $taskInY]), ['column_id' => $columnX->id])
            ->assertRedirect();

        $this->assertSame($columnY->id, $taskInX->fresh()->project_column_id);
        $this->assertSame($columnX->id, $taskInY->fresh()->project_column_id);
    }

    public function test_an_invalid_move_returns_a_validation_error_not_a_server_error(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.tasks.move', [$board, $task]), [
                'column_id' => $column->id,
                'before_task_id' => 999999,
            ])
            ->assertStatus(302); // validation redirect back, never a 500
    }
}
