<?php

namespace Tests\Feature\Perf;

use App\Models\ProjectBoard;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TaskNotificationFeed loads up to 50 notification rows and then batches
 * every access-filter lookup (team membership, board, task, private-board
 * membership) with whereIn — see that class's own docblocks for why. This
 * guards the batching itself: the whole point is that the feed costs the
 * SAME number of queries whether the recipient has 3 notifications or 30,
 * one of each per distinct board/task rather than one per row.
 */
class TaskNotificationFeedQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{count: int, status: int, body: array} */
    private function profile(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson($uri);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, sprintf("[query-budget] %-40s status=%d queries=%d\n", $uri, $response->getStatusCode(), $count));

        return ['count' => $count, 'status' => $response->status(), 'body' => $response->json()];
    }

    /**
     * A real team member, not a stranger: the whole point of this test is to
     * exercise the SAME code path a visible notification actually takes —
     * team membership lookup, board/task batching, the Gate checks — not an
     * artificially cheap all-hidden run.
     */
    private function member(User $owner): User
    {
        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', 'operator')->sole()->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    private function seedNotifications(User $owner, User $recipient, int $count): void
    {
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Launch', null, 'team');
        $column = $board->columns()->first();

        for ($i = 0; $i < $count; $i++) {
            $task = Task::factory()->for($owner)->create([
                'project_board_id' => $board->id,
                'project_column_id' => $column->id,
                'title' => "Task {$i}",
            ]);
            $this->notify($recipient, $task, $board, $owner);
        }
    }

    private function notify(User $recipient, Task $task, ProjectBoard $board, User $actor): void
    {
        $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TaskAssigned::class,
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
        ]);
    }

    public function test_the_feed_runs_the_same_number_of_queries_for_3_and_for_30_notifications(): void
    {
        $owner = User::factory()->create();
        $recipient = $this->member($owner);
        $this->seedNotifications($owner, $recipient, 3);
        $this->actingAs($recipient);

        $small = $this->profile(route('notifications.tasks.index'));
        $this->assertSame(200, $small['status']);
        // Real results, not an accidentally-cheap all-hidden run: every
        // notification here is for a real team member on a team board, so
        // all 3 must come back visible.
        $this->assertSame(3, $small['body']['unread']);
        $this->assertCount(3, $small['body']['items']);

        $owner2 = User::factory()->create();
        $recipient2 = $this->member($owner2);
        $this->seedNotifications($owner2, $recipient2, 30);
        $this->actingAs($recipient2);

        $large = $this->profile(route('notifications.tasks.index'));
        $this->assertSame(200, $large['status']);
        // 30 notifications, all visible, all unread: unread counts every
        // visible unread row among the 50 loaded, not just the 30 shown —
        // here that happens to be the same number, since 30 is exactly the
        // MAX_ITEMS cap too.
        $this->assertSame(30, $large['body']['unread']);
        $this->assertCount(30, $large['body']['items']);

        $this->assertSame(
            $small['count'],
            $large['count'],
            'notifications.tasks.index query count grew with the number of notifications — looks like an N+1'
        );
    }
}
