<?php

namespace App\Services\Projects;

use App\Models\ProjectBoard;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTaskWorkflow
{
    public function move(Task $task, ProjectColumn $target, ?int $beforeTaskId, User $actor): Task
    {
        // Two moves swapping tasks between the same pair of columns deadlock
        // without this: move A locks task-in-X then wants column Y, while
        // move B (the other direction) locks task-in-Y then wants column X —
        // each then needs the sibling list of the OTHER's target column,
        // which includes the row the other transaction is already holding.
        // MySQL detects the cycle and kills one with 1213/40001, which the
        // controller had no choice but to surface as a 500. Locking the
        // board row before any of that — the same row for every move on this
        // board, regardless of which columns are involved — forces every
        // concurrent move to queue behind a single lock instead of racing
        // each other for two different ones, so the cycle above can no
        // longer form. Cost: moves on the same board never run in parallel,
        // even when they touch disjoint columns; acceptable because a single
        // drag-and-drop write is a few milliseconds, and the alternative was
        // an unpredictable 500 under load. The retry (attempts: 3) is a
        // second line of defence for whatever deadlock this ordering doesn't
        // anticipate — MySQL guarantees a deadlock kills one party, not zero,
        // so the transaction is safe to simply replay.
        return DB::transaction(function () use ($task, $target, $beforeTaskId, $actor): Task {
            ProjectBoard::whereKey($target->board_id)->where('team_id', $target->team_id)->lockForUpdate()->firstOrFail();
            $locked = Task::whereKey($task->id)->where('team_id', $target->team_id)
                ->where('project_board_id', $target->board_id)->lockForUpdate()->firstOrFail();
            $column = ProjectColumn::whereKey($target->id)->where('team_id', $locked->team_id)
                ->where('board_id', $locked->project_board_id)->lockForUpdate()->firstOrFail();
            $fromColumn = $locked->project_column_id;

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
        }, 3);
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
