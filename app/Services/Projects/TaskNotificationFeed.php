<?php

namespace App\Services\Projects;

use App\Models\ProjectBoard;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the task-assignment notification feed. Backend only — nothing in
 * this stage renders it; a bell is the next stage.
 *
 * A notification row is a snapshot taken at assignment time and nothing
 * updates it afterward, so it can easily outlive the access that produced
 * it: the recipient may since have left the team, lost the board (removed
 * from a private board's membership, or the board was never theirs to begin
 * with if membership changed), or the card itself may be gone. Showing a
 * stale row would leak a title the recipient can no longer actually see, so
 * every row here is re-checked against LIVE state before it's shown — never
 * trusted just because it exists.
 */
class TaskNotificationFeed
{
    /** Hard cap on rows read before filtering — see build()'s docblock for why this keeps the query count flat. */
    private const LOAD_LIMIT = 50;

    private const MAX_ITEMS = 30;

    private const UNREAD_DISPLAY_CAP = 99;

    /**
     * @return array{unread: int, items: list<array<string, mixed>>}
     */
    public function build(User $user): array
    {
        $notifications = $user->notifications()
            ->where('type', TaskAssigned::class)
            ->limit(self::LOAD_LIMIT)
            ->get();

        if ($notifications->isEmpty()) {
            return ['unread' => 0, 'items' => []];
        }

        $boardIds = [];
        $taskIds = [];
        foreach ($notifications as $notification) {
            $boardIds[] = (int) $notification->data['board_id'];
            $taskIds[] = (int) $notification->data['task_id'];
        }

        // Two batched loads, never one query per notification: this plus the
        // LOAD_LIMIT cap above is the whole point — the query count must
        // stay flat whether the recipient has 3 notifications or 30, not
        // grow with how many rows there are to check.
        $boards = ProjectBoard::whereIn('id', array_unique($boardIds))->get()->keyBy('id');
        $tasks = Task::whereIn('id', array_unique($taskIds))->get()->keyBy('id');

        // Already a single cached snapshot shared with every other
        // authorization check this request makes (HasTeams) — nothing left
        // to batch here.
        $memberships = $user->cachedTeamMemberships();

        // The one sub-check ProjectAccess::canView() cannot do for free in a
        // loop: its private-board branch hits project_board_members with its
        // own exists() query per call, which would cost one query per
        // distinct private board among the candidates. Pre-fetching every
        // candidate private board's membership in a single whereIn (and
        // reusing the same PROJECTS_VIEW/PROJECTS_MANAGE Gate checks
        // boardVisible() below runs per board — already free, see its own
        // docblock) keeps this at one query total regardless of how many
        // notifications or private boards are in play.
        $privateBoardIds = $boards->filter(fn (ProjectBoard $board) => $board->visibility === 'private')->keys()->all();
        $memberBoardIds = $privateBoardIds === [] ? [] : DB::table('project_board_members')
            ->where('user_id', $user->id)
            ->whereIn('board_id', $privateBoardIds)
            ->pluck('board_id')
            ->all();

        $visible = $notifications->filter(function ($notification) use ($memberships, $boards, $tasks, $user, $memberBoardIds) {
            $board = $boards->get((int) $notification->data['board_id']);
            $task = $tasks->get((int) $notification->data['task_id']);

            // Missing board/task: whereIn() above simply returns fewer rows
            // than ids asked for — a board that no longer exists (shouldn't
            // happen, boards aren't hard-deleted) or a task that is gone
            // (Task uses SoftDeletes, so delete() alone is enough to drop it
            // from this set) reads as "not found" here with no special case.
            if ($board === null || $task === null) {
                return false;
            }

            // The board's own (live) team_id, not the notification's stored
            // one: boards never change teams, so these always agree, but the
            // live value is the one actually being checked against.
            return $memberships->has($board->team_id) && $this->boardVisible($user, $board, $memberBoardIds);
        })->values();

        // Counted over every VISIBLE row among the up-to-50 loaded, not just
        // the (at most 30) ones returned as items: an old unread notification
        // past the 30th visible slot must still count, or the badge would
        // undercount against what "mark all read" actually clears.
        $unread = $visible->whereNull('read_at')->count();

        $items = $visible->take(self::MAX_ITEMS)->map(function ($notification) use ($boards, $tasks, $memberships) {
            $data = $notification->data;
            $board = $boards->get((int) $data['board_id']);
            $task = $tasks->get((int) $data['task_id']);

            return [
                'id' => $notification->id,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at->toIso8601String(),
                'task_id' => $task->id,
                'board_id' => $board->id,
                'team_id' => $board->team_id,
                'team_name' => $memberships->get($board->team_id)?->team_name,
                // Live, not the stored snapshot: both are guaranteed loaded
                // here (the filter above already dropped any row whose board
                // or task is missing), so there is no fallback case left to
                // reach — $data['task_title']/['board_name'] stay on the row
                // purely as the historical record, not read back out here.
                'task_title' => $task->title,
                'board_name' => $board->name,
                'assigned_by_name' => $data['assigned_by_name'],
                'url' => route('projects.tasks.show', [$board, $task]),
            ];
        })->values()->all();

        return [
            'unread' => min($unread, self::UNREAD_DISPLAY_CAP),
            'items' => $items,
        ];
    }

    /**
     * Same rule as ProjectAccess::canView(), reusing the same Gate checks it
     * does — just with the private-board membership test swapped for a
     * pre-batched lookup instead of canView()'s own per-call query. The
     * PROJECTS_VIEW/PROJECTS_MANAGE Gate calls below are not re-implemented
     * rules either: they run through the exact same Gate::before owner
     * bypass and per-team permission cache (HasTeams) every other
     * authorization check in the app shares, so repeating them per board
     * here costs a query only once per distinct TEAM among the candidates —
     * never once per notification.
     */
    private function boardVisible(User $user, ProjectBoard $board, array $memberBoardIds): bool
    {
        if (! Gate::forUser($user)->allows(PermissionCatalog::PROJECTS_VIEW, [$board])) {
            return false;
        }

        return $board->visibility !== 'private'
            || $board->created_by === $user->id
            || Gate::forUser($user)->allows(PermissionCatalog::PROJECTS_MANAGE, [$board])
            || in_array($board->id, $memberBoardIds, true);
    }
}
