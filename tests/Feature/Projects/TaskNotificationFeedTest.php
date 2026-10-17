<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectBoard;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * App\Services\Projects\TaskNotificationFeed and the three JSON endpoints in
 * front of it (TaskNotificationController). Backend only — no bell/view
 * exists yet, so these tests exercise the HTTP contract and the access
 * filter directly, never a page.
 */
class TaskNotificationFeedTest extends TestCase
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

    private function boardWithTask(User $owner, string $visibility = 'team'): array
    {
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Launch', null, $visibility);
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
            'title' => 'Ship the release notes',
        ]);

        return [$board, $task];
    }

    private function notify(User $recipient, Task $task, ProjectBoard $board, User $actor, array $overrides = []): DatabaseNotification
    {
        return $recipient->notifications()->create(array_merge([
            'id' => (string) Str::uuid(),
            'type' => TaskAssigned::class,
            'created_at' => now(),
            'data' => [
                'task_id' => $task->id,
                'board_id' => $board->id,
                'team_id' => $board->team_id,
                'task_title' => $task->title,
                'board_name' => $board->name,
                'assigned_by_id' => $actor->id,
                'assigned_by_name' => $actor->name,
                'due_at' => null,
            ],
        ], $overrides));
    }

    public function test_list_returns_only_the_callers_own_items_newest_first_with_expected_keys_and_url(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $otherRecipient = $this->member($owner, 'operator');
        [$board, $taskOld] = $this->boardWithTask($owner);
        $taskNew = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
            'title' => 'Fix the checkout bug',
        ]);

        $this->notify($operator, $taskOld, $board, $owner, ['created_at' => now()->subMinutes(10)]);
        $this->notify($operator, $taskNew, $board, $owner, ['created_at' => now()->subMinute()]);
        // A notification for someone else entirely must never leak into the
        // caller's own feed.
        $this->notify($otherRecipient, $taskOld, $board, $owner);

        $response = $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->assertOk();
        $json = $response->json();

        $this->assertSame(2, $json['unread']);
        $this->assertCount(2, $json['items']);

        $first = $json['items'][0];
        $this->assertSame($taskNew->id, $first['task_id']);
        $this->assertSame(
            ['id', 'read', 'created_at', 'task_id', 'board_id', 'team_id', 'team_name', 'team_is_personal', 'task_title', 'board_name', 'assigned_by_name', 'url'],
            array_keys($first)
        );
        $this->assertFalse($first['read']);
        $this->assertSame($board->id, $first['board_id']);
        $this->assertSame($owner->current_team_id, $first['team_id']);
        // $owner here is a plain factory user who never created an explicit
        // team, so their current_team_id is still the hidden personal one.
        $this->assertTrue($first['team_is_personal']);
        $this->assertSame('Fix the checkout bug', $first['task_title']);
        $this->assertSame('Launch', $first['board_name']);
        $this->assertSame($owner->name, $first['assigned_by_name']);
        $this->assertSame(route('projects.tasks.show', [$board, $taskNew]), $first['url']);

        $second = $json['items'][1];
        $this->assertSame($taskOld->id, $second['task_id']);
    }

    public function test_hides_a_notification_after_the_user_is_removed_from_the_team(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $this->notify($operator, $task, $board, $owner);

        $this->assertSame(1, $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->json('unread'));

        TeamMembership::where('team_id', $owner->current_team_id)->where('user_id', $operator->id)->delete();
        $operator->flushPermissionCache();

        $json = $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->assertOk()->json();
        $this->assertSame(0, $json['unread']);
        $this->assertSame([], $json['items']);
    }

    public function test_hides_a_notification_for_a_private_board_the_user_is_no_longer_a_member_of(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner, 'private');
        $board->members()->attach($operator->id, ['team_id' => $board->team_id]);
        $this->notify($operator, $task, $board, $owner);

        $this->assertSame(1, $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->json('unread'));

        $board->members()->detach($operator->id);

        $json = $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->assertOk()->json();
        $this->assertSame(0, $json['unread']);
        $this->assertSame([], $json['items']);
    }

    public function test_hides_a_notification_for_a_deleted_task(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $this->notify($operator, $task, $board, $owner);

        $this->assertSame(1, $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->json('unread'));

        $task->delete();

        $json = $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->assertOk()->json();
        $this->assertSame(0, $json['unread']);
        $this->assertSame([], $json['items']);
    }

    public function test_team_is_personal_is_false_for_an_explicit_team(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $this->actingAs($owner)->post(route('teams.store'), ['name' => 'Launch Co'])->assertRedirect();
        $owner->flushPermissionCache();
        $operator->flushPermissionCache();

        [$board, $task] = $this->boardWithTask($owner);
        $this->notify($operator, $task, $board, $owner);

        $json = $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->assertOk()->json();
        $this->assertFalse($json['items'][0]['team_is_personal']);
        $this->assertSame('Launch Co', $json['items'][0]['team_name']);
    }

    public function test_shows_the_live_task_title_after_a_rename(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $this->notify($operator, $task, $board, $owner, [
            'data' => [
                'task_id' => $task->id,
                'board_id' => $board->id,
                'team_id' => $board->team_id,
                'task_title' => 'Ship the release notes',
                'board_name' => $board->name,
                'assigned_by_id' => $owner->id,
                'assigned_by_name' => $owner->name,
                'due_at' => null,
            ],
        ]);

        $task->forceFill(['title' => 'Ship the release notes (renamed)'])->save();

        $json = $this->actingAs($operator)->getJson(route('notifications.tasks.index'))->assertOk()->json();
        $this->assertSame('Ship the release notes (renamed)', $json['items'][0]['task_title']);
    }

    public function test_marking_one_notification_read_only_changes_the_callers_own_row(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $mine = $this->notify($operator, $task, $board, $owner);

        $this->actingAs($operator)
            ->postJson(route('notifications.tasks.read', $mine->id))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_another_users_notification_id_404s_and_is_left_untouched(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $stranger = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $notTheirs = $this->notify($stranger, $task, $board, $owner);

        $this->actingAs($operator)
            ->postJson(route('notifications.tasks.read', $notTheirs->id))
            ->assertNotFound();

        $this->assertNull($notTheirs->fresh()->read_at);
    }

    public function test_an_unknown_notification_id_404s(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson(route('notifications.tasks.read', (string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_mark_all_read_only_changes_the_callers_own_rows(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $stranger = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $mineA = $this->notify($operator, $task, $board, $owner);
        $mineB = $this->notify($operator, $task, $board, $owner);
        $notMine = $this->notify($stranger, $task, $board, $owner);

        $this->actingAs($operator)
            ->postJson(route('notifications.tasks.read-all'))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertNotNull($mineA->fresh()->read_at);
        $this->assertNotNull($mineB->fresh()->read_at);
        $this->assertNull($notMine->fresh()->read_at);
    }

    public function test_marking_an_already_hidden_notification_read_is_idempotent_and_still_succeeds(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        [$board, $task] = $this->boardWithTask($owner);
        $notification = $this->notify($operator, $task, $board, $owner);
        $task->delete();

        $this->actingAs($operator)
            ->postJson(route('notifications.tasks.read', $notification->id))
            ->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);

        // A second mark-read on the same (now-read, still-hidden) row is a
        // harmless no-op, not an error.
        $this->actingAs($operator)
            ->postJson(route('notifications.tasks.read', $notification->id))
            ->assertOk();
    }

    public function test_unauthenticated_requests_are_redirected_to_login(): void
    {
        $this->get(route('notifications.tasks.index'))->assertRedirect(route('login'));
    }
}
