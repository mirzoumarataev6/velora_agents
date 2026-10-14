<?php

namespace App\Services\Projects;

use App\Models\ProjectBoard;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ProjectAccess
{
    /** @return Builder<ProjectBoard> */
    public function visibleBoards(User $user): Builder
    {
        $teamId = $user->activeTeamId();
        $query = ProjectBoard::query()->where('team_id', $teamId ?? -1)->whereNull('archived_at');

        if ($teamId !== null && $user->can('projects.manage')) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user) {
            $visible->where('visibility', 'team')
                ->orWhere('created_by', $user->id)
                ->orWhereExists(function ($members) use ($user) {
                    $members->selectRaw('1')->from('project_board_members')
                        ->whereColumn('project_board_members.board_id', 'project_boards.id')
                        ->where('project_board_members.user_id', $user->id);
                });
        });
    }

    public function authorize(User $user, ProjectBoard $board, string $permission = 'projects.view'): void
    {
        // Lists follow the active workspace. A URL for another team does not
        // silently switch navigation, even when the user belongs to both.
        abort_unless($user->activeTeamId() === (int) $board->team_id, 404);
        Gate::forUser($user)->authorize($permission, [$board]);

        // Archived boards remain readable for recovery, but no card or
        // configuration write should revive activity there implicitly.
        abort_if($board->archived_at !== null && $permission !== 'projects.view', 403);

        if ($board->visibility === 'private' && $board->created_by !== $user->id
            && ! Gate::forUser($user)->allows('projects.manage', [$board])
            && ! $board->members()->where('users.id', $user->id)->exists()) {
            abort(404);
        }
    }
}
