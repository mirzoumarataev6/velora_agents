<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectBoard;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Projects\ProjectBoards;
use App\Services\Projects\ProjectTaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Assigning a project task to someone stores them a notification
 * (App\Notifications\TaskAssigned, database channel only — no bell/UI yet).
 * These tests are about WHO gets notified and WHEN, not what the eventual
 * bell renders.
 */
class ProjectTaskAssignedNotificationTest extends TestCase
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

    private function teamBoard(User $owner): ProjectBoard
    {
        return app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Launch', null, 'team');
    }

    public function test_creating_a_task_with_an_assignee_sends_exactly_one_notification_with_the_expected_data(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $column = $board->columns()->first();

        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id,
            'title' => 'Ship the release notes',
            'due_at' => '2026-12-01 10:00:00',
            'assignee_ids' => [$operator->id],
        ])->assertRedirect();

        $task = Task::where('project_board_id', $board->id)->sole();

        $this->assertSame(1, $operator->notifications()->count());
        /** @var DatabaseNotification $notification */
        $notification = $operator->notifications()->sole();
        $this->assertSame(TaskAssigned::class, $notification->type);
        $this->assertSame([
            'task_id' => $task->id,
            'board_id' => $board->id,
            'team_id' => $owner->current_team_id,
            'task_title' => 'Ship the release notes',
            'board_name' => 'Launch',
            'assigned_by_id' => $owner->id,
            'assigned_by_name' => $owner->name,
            'due_at' => $task->due_at->toIso8601String(),
        ], $notification->data);
    }

    public function test_self_assign_sends_nothing(): void
    {
        $owner = User::factory()->create();
        $board = $this->teamBoard($owner);
        $column = $board->columns()->first();

        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id,
            'title' => 'I will do this myself',
            'assignee_ids' => [$owner->id],
        ])->assertRedirect();

        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_re_saving_a_card_with_the_same_assignee_sends_nothing_new(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);
        $task->assignees()->attach($operator->id, [
            'team_id' => $board->team_id, 'board_id' => $board->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'title' => 'Renamed, same assignee',
            'assignees_present' => '1',
            'assignee_ids' => [$operator->id],
        ])->assertRedirect();

        $this->assertSame(0, $operator->notifications()->count());
    }

    public function test_removing_an_assignee_sends_nothing(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);
        $task->assignees()->attach($operator->id, [
            'team_id' => $board->team_id, 'board_id' => $board->id,
        ]);
        // The one notification from the original assignment, made directly
        // rather than through the endpoint so this test only has to prove
        // REMOVAL creates nothing, not re-prove creation does.
        $operator->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TaskAssigned::class,
            'data' => ['task_id' => $task->id],
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'title' => 'Unassigned now',
            'assignees_present' => '1',
            'assignee_ids' => [],
        ])->assertRedirect();

        $this->assertSame(0, $task->assignees()->count());
        $this->assertSame(1, $operator->notifications()->count());
    }

    public function test_two_new_assignees_each_get_exactly_one_notification(): void
    {
        $owner = User::factory()->create();
        $operatorA = $this->member($owner, 'operator');
        $operatorB = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'assignees_present' => '1',
            'assignee_ids' => [$operatorA->id, $operatorB->id],
        ])->assertRedirect();

        $this->assertSame(1, $operatorA->notifications()->count());
        $this->assertSame(1, $operatorB->notifications()->count());
    }

    public function test_adding_one_more_assignee_to_an_existing_set_notifies_only_the_new_one(): void
    {
        $owner = User::factory()->create();
        $operatorA = $this->member($owner, 'operator');
        $operatorB = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);
        $task->assignees()->attach($operatorA->id, [
            'team_id' => $board->team_id, 'board_id' => $board->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'assignees_present' => '1',
            'assignee_ids' => [$operatorA->id, $operatorB->id],
        ])->assertRedirect();

        $this->assertSame(0, $operatorA->notifications()->count());
        $this->assertSame(1, $operatorB->notifications()->count());
    }

    /**
     * The notification is registered via DB::afterCommit from inside the
     * same DB::transaction() the assignment itself is written in (see
     * ProjectTaskController::notifyNewAssignees). Forcing ProjectTaskWorkflow
     * ::record() — called later in that same transaction — to throw proves
     * the callback is really deferred to a real commit, not fired early:
     * if it were sent synchronously, this test would see a notification for
     * an assignment that the rollback below then undid.
     */
    public function test_a_rolled_back_write_sends_nothing(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);

        $this->mock(ProjectTaskWorkflow::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('forced rollback'));
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
                'assignees_present' => '1',
                'assignee_ids' => [$operator->id],
            ]);
            $this->fail('Expected the forced RuntimeException to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced rollback', $e->getMessage());
        }

        $this->assertSame(0, $task->assignees()->count());
        $this->assertSame(0, $operator->notifications()->count());
    }

    public function test_a_user_outside_the_team_cannot_be_assigned_and_receives_nothing(): void
    {
        $owner = User::factory()->create();
        $board = $this->teamBoard($owner);
        $column = $board->columns()->first();
        $outsider = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id,
            'title' => 'Should fail validation',
            'assignee_ids' => [$outsider->id],
        ])->assertInvalid(['assignee_ids.0']);

        $this->assertSame(0, Task::where('project_board_id', $board->id)->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_a_team_member_outside_a_private_board_cannot_be_assigned_and_receives_nothing(): void
    {
        $owner = User::factory()->create();
        $boardOperator = $this->member($owner, 'operator');
        $outsiderOperator = $this->member($owner, 'operator');
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Confidential', null, 'private');
        $board->members()->attach($boardOperator->id, ['team_id' => $board->team_id]);
        $column = $board->columns()->first();

        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id,
            'title' => 'Private board card',
            'assignee_ids' => [$outsiderOperator->id],
        ])->assertInvalid(['assignee_ids']);

        $this->assertSame(0, Task::where('project_board_id', $board->id)->count());
        $this->assertSame(0, $outsiderOperator->notifications()->count());

        // The board member IS a legitimate recipient — same request shape,
        // only the recipient's standing differs, pinning the previous
        // assertion to privacy rather than some unrelated validation error.
        $this->actingAs($owner)->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id,
            'title' => 'Private board card for a real member',
            'assignee_ids' => [$boardOperator->id],
        ])->assertRedirect();
        $this->assertSame(1, $boardOperator->notifications()->count());
    }

    public function test_deleting_an_account_removes_its_notifications(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $board = $this->teamBoard($owner);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
        ]);

        $this->actingAs($owner)->patch(route('projects.tasks.update', [$board, $task]), [
            'assignees_present' => '1',
            'assignee_ids' => [$operator->id],
        ])->assertRedirect();
        $this->assertSame(1, $operator->notifications()->count());
        $operatorId = $operator->id;

        $operator->delete();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $operatorId,
        ]);
    }
}
