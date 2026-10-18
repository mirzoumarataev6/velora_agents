<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectLabel;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 7: the activity feed used to say only "updated the task" /
 * "moved the task" for every edit and every drag, with no way to tell what
 * actually changed short of comparing the card's current state to memory.
 * ProjectTaskWorkflow's events already carried `details` (changed fields,
 * from/to column ids, and now also assignee/label diffs) — this renders it.
 */
class ProjectActivityLogDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $owner, string $role): User
    {
        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', $role)->sole()->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    public function test_an_update_shows_which_fields_changed(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id, 'title' => 'Original',
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.tasks.update', [$board, $task]), ['title' => 'Changed title', 'priority' => 'high'])
            ->assertRedirect();

        $response = $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk();
        $response->assertSee(__('projects.activity_log.changed_fields', [
            'fields' => __('projects.activity_fields.title').', '.__('projects.activity_fields.priority'),
        ]));
    }

    public function test_assigning_and_unassigning_shows_member_names(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'assignees_present' => 1, 'assignee_ids' => [$operator->id],
        ])->assertRedirect();

        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk()
            ->assertSee(__('projects.activity_log.assignees_added', ['names' => $operator->name]));

        $this->patch(route('projects.tasks.update', [$board, $task]), [
            'assignees_present' => 1, 'assignee_ids' => [],
        ])->assertRedirect();

        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk()
            ->assertSee(__('projects.activity_log.assignees_removed', ['names' => $operator->name]));
    }

    public function test_a_removed_label_falls_back_to_deleted_label_once_the_label_itself_is_gone(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $label = $board->labels()->create(['team_id' => $owner->current_team_id, 'name' => 'Urgent', 'color' => 'rose']);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $column->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'labels_present' => 1, 'label_ids' => [$label->id],
        ])->assertRedirect();
        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk()
            ->assertSee(__('projects.activity_log.labels_added', ['names' => 'Urgent']));

        $this->patch(route('projects.tasks.update', [$board, $task]), [
            'labels_present' => 1, 'label_ids' => [],
        ])->assertRedirect();
        ProjectLabel::whereKey($label->id)->delete();

        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk()
            ->assertSee(__('projects.activity_log.labels_removed', ['names' => __('projects.deleted_label')]));
    }

    public function test_a_move_shows_the_from_and_to_column_names(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $columns = $board->columns()->orderBy('position')->get();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $columns[0]->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.tasks.move', [$board, $task]), ['column_id' => $columns[1]->id])
            ->assertRedirect();

        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk()
            ->assertSee(__('projects.activity_log.moved_columns', ['from' => $columns[0]->name, 'to' => $columns[1]->name]));
    }
}
