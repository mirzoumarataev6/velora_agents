<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectBoard;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PHASE 1 item 4: `due_at` is a MySQL TIMESTAMP column (1970-01-01 00:00:01
 * to 2038-01-19 03:14:07 UTC — see Task::DUE_AT_RULES). Laravel's own `date`
 * rule happily accepts 9999-12-31 or 1900-01-01, so those reached the
 * INSERT/UPDATE itself and came back as MySQL error 1292 — a bare 500, not
 * a validation error the owner could act on.
 */
class ProjectTaskDueAtRangeTest extends TestCase
{
    use RefreshDatabase;

    private function board(User $owner): ProjectBoard
    {
        return app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Due dates', null, 'team');
    }

    public static function outOfRangeDueAtProvider(): array
    {
        return [
            'far future' => ['9999-12-31'],
            'before the epoch' => ['1900-01-01'],
        ];
    }

    #[DataProvider('outOfRangeDueAtProvider')]
    public function test_creating_a_task_with_an_out_of_range_due_at_is_rejected_not_a_server_error(string $dueAt): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();

        $this->actingAs($owner)
            ->post(route('projects.tasks.store', $board), ['column_id' => $column->id, 'title' => 'Ship it', 'due_at' => $dueAt])
            ->assertSessionHasErrors('due_at');

        $this->assertDatabaseMissing('tasks', ['title' => 'Ship it']);
    }

    #[DataProvider('outOfRangeDueAtProvider')]
    public function test_updating_a_task_with_an_out_of_range_due_at_is_rejected_not_a_server_error(string $dueAt): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->undated()->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Existing',
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.tasks.update', [$board, $task]), ['due_at' => $dueAt])
            ->assertSessionHasErrors('due_at');

        $this->assertNull($task->fresh()->due_at);
    }

    #[DataProvider('outOfRangeDueAtProvider')]
    public function test_adding_a_checklist_item_with_an_out_of_range_due_at_is_rejected_not_a_server_error(string $dueAt): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id,
        ]);

        $this->actingAs($owner)
            ->post(route('projects.checklist.store', [$board, $task]), ['title' => 'Checklist item', 'due_at' => $dueAt])
            ->assertSessionHasErrors('due_at');

        $this->assertDatabaseMissing('project_checklist_items', ['title' => 'Checklist item']);
    }

    public function test_a_due_at_inside_the_valid_range_is_accepted(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();

        $this->actingAs($owner)
            ->post(route('projects.tasks.store', $board), [
                'column_id' => $column->id, 'title' => 'Ship it', 'due_at' => '2030-06-15 10:00:00',
            ])
            ->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'Ship it']);
    }

    public function test_the_error_message_is_a_translated_sentence_not_a_raw_field_name(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();

        $this->actingAs($owner)
            ->from(route('projects.boards.show', $board))
            ->post(route('projects.tasks.store', $board), ['column_id' => $column->id, 'title' => 'Ship it', 'due_at' => '9999-12-31'])
            ->assertRedirect()
            ->assertSessionHasErrors(['due_at' => __('projects.errors.due_at_range')]);
    }
}
