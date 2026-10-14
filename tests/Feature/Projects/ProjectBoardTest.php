<?php

namespace Tests\Feature\Projects;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\ProjectBoard;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Account\DeletesAccount;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectBoards;
use App\Services\Projects\ProjectTaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
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

    public function test_board_card_assignment_checklist_comment_and_stage_are_one_workflow(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $this->actingAs($owner)->post(route('projects.boards.store'), [
            'name' => 'Launch', 'visibility' => 'team', 'accent' => 'violet',
        ])->assertRedirect();
        $board = ProjectBoard::where('team_id', $owner->current_team_id)->where('name', 'Launch')->sole();
        $columns = $board->columns()->get();
        $this->get(route('projects.boards.show', $board))->assertOk()->assertSee('Launch');

        $this->post(route('projects.tasks.store', $board), [
            'column_id' => $columns[0]->id, 'title' => 'Prepare release',
            'priority' => 'high', 'assignee_ids' => [$operator->id],
        ])->assertRedirect();
        $task = Task::where('project_board_id', $board->id)->sole();
        $this->assertDatabaseHas('project_task_assignees', ['task_id' => $task->id, 'user_id' => $operator->id]);
        $this->actingAs($operator)->get(route('projects.my'))->assertOk()->assertSee('Prepare release');
        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->assertSee('Prepare release')->assertSee(__('projects.activity'));

        $this->patch(route('projects.tasks.move', [$board, $task]), ['column_id' => $columns[2]->id])->assertRedirect();
        $this->assertNotNull($task->fresh()->done_at);
        $this->post(route('projects.checklist.store', [$board, $task]), ['title' => 'Smoke test'])->assertRedirect();
        $this->post(route('projects.comments.store', [$board, $task]), ['body' => 'Ready for review'])->assertRedirect();
        $this->assertDatabaseHas('project_checklist_items', ['task_id' => $task->id, 'title' => 'Smoke test']);
        $this->assertDatabaseHas('project_task_events', ['task_id' => $task->id, 'kind' => 'comment', 'body' => 'Ready for review']);
        $this->patch(route('projects.tasks.move', [$board, $task]), ['column_id' => $columns[0]->id])->assertRedirect();
        $this->assertNull($task->fresh()->done_at);
    }

    public function test_role_and_private_board_visibility_are_enforced(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $viewer = $this->member($owner, 'viewer');
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Strategy', null, 'private');
        $this->actingAs($operator)->get(route('projects.index'))->assertOk()->assertDontSee('Strategy');
        $this->get(route('projects.boards.show', $board))->assertNotFound();
        $this->actingAs($owner)->post(route('projects.boards.members.store', $board), ['user_id' => $operator->id])->assertRedirect();
        $this->actingAs($operator)->get(route('projects.boards.show', $board))->assertOk()->assertSee('Strategy');
        $this->actingAs($viewer)->get(route('projects.boards.show', $board))->assertNotFound();
        $this->post(route('projects.tasks.store', $board), [
            'column_id' => $board->columns()->first()->id, 'title' => 'No access',
        ])->assertForbidden();
        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $board->columns()->first()->id,
            'title' => 'Assign to outsider', 'assignee_ids' => [$viewer->id],
        ])->assertSessionHasErrors('assignee_ids');
    }

    public function test_other_board_columns_and_wip_limit_are_rejected(): void
    {
        $owner = User::factory()->create();
        $boards = app(ProjectBoards::class);
        $first = $boards->create($owner->current_team_id, $owner->id, 'A');
        $second = $boards->create($owner->current_team_id, $owner->id, 'B');
        $column = $first->columns()->first();
        $column->forceFill(['wip_limit' => 1])->save();

        $this->actingAs($owner)->post(route('projects.tasks.store', $first), [
            'column_id' => $second->columns()->first()->id, 'title' => 'Wrong board',
        ])->assertSessionHasErrors('column_id');
        $this->post(route('projects.tasks.store', $first), [
            'column_id' => $column->id, 'title' => 'First',
        ])->assertRedirect();
        $this->post(route('projects.tasks.store', $first), [
            'column_id' => $column->id, 'title' => 'Second',
        ])->assertSessionHasErrors('column_id');
        $this->assertSame(1, Task::where('project_board_id', $first->id)->count());
    }

    public function test_custom_role_without_project_permission_cannot_view_or_mutate(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, 'viewer');
        $member->membershipIn($owner->current_team_id)->role->syncPermissions([PermissionCatalog::ANALYTICS_VIEW]);
        $member->flushPermissionCache();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Hidden');

        $this->actingAs($member)->get(route('projects.index'))->assertForbidden();
        $this->get(route('projects.boards.show', $board))->assertForbidden();
        $this->post(route('projects.tasks.store', $board), [
            'column_id' => $board->columns()->first()->id, 'title' => 'Denied',
        ])->assertForbidden();
    }

    public function test_archived_board_is_recoverable_but_blocks_task_writes(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'History');
        $this->actingAs($owner)->patch(route('projects.boards.archive', $board))->assertRedirect();
        $this->get(route('projects.index'))->assertOk()->assertSee('History');
        $this->post(route('projects.tasks.store', $board), [
            'column_id' => $board->columns()->first()->id, 'title' => 'After archive',
        ])->assertForbidden();
        $this->patch(route('projects.boards.archive', $board))->assertRedirect();
        $this->assertNull($board->fresh()->archived_at);
    }

    public function test_edit_form_can_clear_all_assignees_and_labels(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, 'operator');
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Planning');
        $label = $board->labels()->create(['team_id' => $owner->current_team_id, 'name' => 'Release', 'color' => 'rose']);
        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $board->columns()->first()->id, 'title' => 'Card',
            'assignee_ids' => [$member->id], 'label_ids' => [$label->id],
        ])->assertRedirect();
        $task = Task::where('project_board_id', $board->id)->sole();
        $this->patch(route('projects.tasks.update', [$board, $task]), [
            'title' => 'Card', 'assignees_present' => '1', 'labels_present' => '1',
        ])->assertRedirect();

        $this->assertDatabaseCount('project_task_assignees', 0);
        $this->assertDatabaseCount('project_task_labels', 0);
    }

    public function test_existing_crm_task_is_visible_on_the_default_board_and_mismatched_crm_link_is_rejected(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $other = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();
        $task = Task::factory()->for($owner)->create(['customer_id' => $customer->id, 'deal_id' => $deal->id, 'title' => 'Call back']);
        $board = $task->projectBoard;

        $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->assertSee('Call back');
        $this->patch(route('projects.tasks.update', [$board, $task]), ['customer_id' => $other->id])
            ->assertSessionHasErrors('deal_id');
        $this->assertSame($customer->id, $task->fresh()->customer_id);
    }

    public function test_card_order_and_foreign_team_assignment_are_validated(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Delivery');
        $column = $board->columns()->first();
        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id, 'title' => 'First', 'assignee_ids' => [$otherOwner->id],
        ])->assertSessionHasErrors('assignee_ids.0');
        foreach (['First', 'Second'] as $title) {
            $this->post(route('projects.tasks.store', $board), ['column_id' => $column->id, 'title' => $title])->assertRedirect();
        }
        $first = Task::where('project_board_id', $board->id)->where('title', 'First')->sole();
        $second = Task::where('project_board_id', $board->id)->where('title', 'Second')->sole();
        $this->patch(route('projects.tasks.move', [$board, $second]), [
            'column_id' => $column->id, 'before_task_id' => $first->id,
        ])->assertRedirect();
        $this->assertSame(['Second', 'First'], Task::where('project_column_id', $column->id)
            ->orderBy('position')->pluck('title')->all());
        $this->patch(route('projects.tasks.move', [$board, $second]), [
            'column_id' => $column->id, 'before_task_id' => 987654,
        ])->assertSessionHasErrors('before_task_id');
    }

    public function test_manager_can_reorder_stages_without_changing_task_links(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Flow');
        $columns = $board->columns()->get();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id, 'project_column_id' => $columns[1]->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.columns.order', [$board, $columns[1]]), [
            'direction' => 'up',
        ])->assertRedirect();

        $this->assertSame([$columns[1]->id, $columns[0]->id, $columns[2]->id],
            $board->columns()->pluck('id')->all());
        $this->assertSame($columns[1]->id, $task->fresh()->project_column_id);
    }

    public function test_closing_a_team_board_keeps_current_assignees_on_the_board(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Team plan');
        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $board->columns()->first()->id,
            'title' => 'Shared card', 'assignee_ids' => [$operator->id],
        ])->assertRedirect();

        $this->patch(route('projects.boards.update', $board), [
            'name' => 'Team plan', 'visibility' => 'private', 'accent' => 'blue',
        ])->assertRedirect();

        $this->assertDatabaseHas('project_board_members', ['board_id' => $board->id, 'user_id' => $operator->id]);
        $this->actingAs($operator)->get(route('projects.boards.show', $board))->assertOk()->assertSee('Shared card');
    }

    public function test_archived_card_is_discoverable_and_restorable_but_cannot_be_edited(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Ops');
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
            'title' => 'Old task',
        ]);
        $this->actingAs($owner)->patch(route('projects.tasks.archive', [$board, $task]))->assertRedirect();
        $this->get(route('projects.boards.show', $board))->assertOk()
            ->assertSee(__('projects.archived_tasks'))->assertDontSee('Old task');
        $this->get(route('projects.boards.show', [$board, 'filter' => 'archived']))
            ->assertOk()->assertSee('Old task');
        $this->patch(route('projects.tasks.update', [$board, $task]), ['title' => 'Changed'])->assertForbidden();
        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk()->assertSee(__('projects.restore'));
        $this->patch(route('projects.tasks.archive', [$board, $task]))->assertRedirect();
        $this->assertNull($task->fresh()->archived_at);
    }

    public function test_switching_active_team_hides_the_previous_teams_boards(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Team A plan');
        TeamMembership::create([
            'team_id' => $otherOwner->current_team_id,
            'user_id' => $owner->id,
            'role_id' => Role::where('team_id', $otherOwner->current_team_id)->where('slug', 'operator')->sole()->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk();
        $owner->forceFill(['current_team_id' => $otherOwner->current_team_id])->save();
        $this->get(route('projects.boards.show', $board))->assertNotFound();
        $this->get(route('projects.index'))->assertOk()->assertDontSee('Team A plan');
    }

    public function test_deleting_a_solo_account_cleans_up_its_board_tasks_and_activity(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Personal work');
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);
        app(ProjectTaskWorkflow::class)->record($task, $owner, 'created');

        app(DeletesAccount::class)->delete($owner);

        $this->assertDatabaseMissing('project_boards', ['id' => $board->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('project_task_events', ['task_id' => $task->id]);
    }
}
