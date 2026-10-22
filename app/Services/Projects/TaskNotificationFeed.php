<?php

namespace App\Services\Projects;

use App\Models\ProjectBoard;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TaskNotificationFeed
{
    private const LOAD_LIMIT = 50;

    private const MAX_ITEMS = 30;

    private const UNREAD_DISPLAY_CAP = 99;

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

        $boards = ProjectBoard::whereIn('id', array_unique($boardIds))->get()->keyBy('id');
        $tasks = Task::whereIn('id', array_unique($taskIds))->get()->keyBy('id');

        $memberships = $user->cachedTeamMemberships();

        $privateBoardIds = $boards->filter(fn (ProjectBoard $board) => $board->visibility === 'private')->keys()->all();
        $memberBoardIds = $privateBoardIds === [] ? [] : DB::table('project_board_members')
            ->where('user_id', $user->id)
            ->whereIn('board_id', $privateBoardIds)
            ->pluck('board_id')
            ->all();

        $visible = $notifications->filter(function ($notification) use ($memberships, $boards, $tasks, $user, $memberBoardIds) {
            $board = $boards->get((int) $notification->data['board_id']);
            $task = $tasks->get((int) $notification->data['task_id']);

            if ($board === null || $task === null) {
                return false;
            }

            return $memberships->has($board->team_id) && $this->boardVisible($user, $board, $memberBoardIds);
        })->values();

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

                'team_is_personal' => (bool) $memberships->get($board->team_id)?->team_is_personal,

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
