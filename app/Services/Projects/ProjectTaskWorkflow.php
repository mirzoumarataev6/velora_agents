<?php

namespace App\Services\Projects;

use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTaskWorkflow
{
    public function move(Task $task, ProjectColumn $target, ?int $beforeTaskId, User $actor): Task
    {
        return DB::transaction(function () use ($task, $target, $beforeTaskId, $actor): Task {
            $locked = Task::whereKey($task->id)->where('team_id', $target->team_id)
                ->where('project_board_id', $target->board_id)->lockForUpdate()->firstOrFail();
            $column = ProjectColumn::whereKey($target->id)->where('team_id', $locked->team_id)
                ->where('board_id', $locked->project_board_id)->lockForUpdate()->firstOrFail();
            $fromColumn = $locked->project_column_id;

            if ($fromColumn !== $column->id && $column->wip_limit !== null && ! $column->is_done
                && Task::where('project_column_id', $column->id)->whereNull('archived_at')->count() >= $column->wip_limit) {
                throw ValidationException::withMessages(['column_id' => __('projects.errors.wip_limit')]);
            }

            $siblings = Task::where('project_column_id', $column->id)->whereNull('archived_at')
                ->whereKeyNot($locked->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $index = $beforeTaskId === null ? $siblings->count() : $siblings->search(fn (Task $sibling) => $sibling->id === $beforeTaskId);
            if ($index === false) {
                throw ValidationException::withMessages(['before_task_id' => __('projects.errors.invalid_position')]);
            }

            $previous = $index > 0 ? $siblings[$index - 1]->position : 0;
            $next = $index < $siblings->count() ? $siblings[$index]->position : null;
            if ($next !== null && $next - $previous <= 1) {
                // Sparse ranks keep common drags to one UPDATE. Once a
                // column runs out of gaps, compact only that column.
                foreach ($siblings as $rank => $sibling) {
                    $sibling->forceFill(['position' => ($rank + 1) * 1000])->save();
                }
                $previous = $index > 0 ? $siblings[$index - 1]->position : 0;
                $next = $index < $siblings->count() ? $siblings[$index]->position : null;
            }
            $position = $next === null ? $previous + 1000 : intdiv($previous + $next, 2);

            $locked->forceFill([
                'project_column_id' => $column->id,
                'position' => $position,
                'done_at' => $column->is_done ? ($locked->done_at ?? now()) : null,
            ])->save();

            if ($fromColumn !== $column->id) {
                $this->record($locked, $actor, 'moved', null, [
                    'from_column_id' => $fromColumn,
                    'to_column_id' => $column->id,
                ]);
            }

            return $locked;
        });
    }

    public function record(Task $task, User $actor, string $kind, ?string $body = null, ?array $details = null): void
    {
        $task->projectEvents()->create([
            'team_id' => $task->team_id,
            'board_id' => $task->project_board_id,
            'actor_id' => $actor->id,
            'kind' => $kind,
            'body' => $body,
            'details' => $details,
        ]);
    }
}
