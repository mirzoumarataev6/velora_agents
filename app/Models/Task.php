<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\TaskPolicy;
use App\Services\Projects\ProjectBoards;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['customer_id', 'deal_id', 'title', 'due_at', 'done_at', 'priority', 'type'])]
#[UsePolicy(TaskPolicy::class)]
class Task extends Model
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const TYPES = ['follow_up', 'call', 'message', 'meeting', 'internal'];

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use SoftDeletes;
    use StampsTeam;

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            // CRM's inline "add reminder" actions are still used on deals
            // and customers. They must land on the same board as tasks made
            // in Projects, even if the old form knows nothing about columns.
            if ($task->project_board_id === null && $task->team_id !== null) {
                $task->project_board_id = app(ProjectBoards::class)
                    ->defaultForTeam((int) $task->team_id, $task->user_id)->id;
            }

            if ($task->project_board_id !== null && $task->project_column_id === null) {
                $task->project_column_id = ProjectColumn::where('team_id', $task->team_id)
                    ->where('board_id', $task->project_board_id)
                    ->where('is_done', $task->done_at !== null)
                    ->orderBy('position')->value('id');
            }

            if (! $task->position && $task->project_column_id !== null) {
                $task->position = ((int) static::where('project_column_id', $task->project_column_id)->max('position')) + 1000;
            }
        });

        static::updating(function (Task $task): void {
            if ($task->isDirty('done_at') && ! $task->isDirty('project_column_id')
                && $task->project_board_id !== null) {
                // CRM's inline completion toggle still edits done_at. Its
                // board column must follow that change or the two screens
                // would disagree about whether the task is finished.
                $task->project_column_id = ProjectColumn::where('team_id', $task->team_id)
                    ->where('board_id', $task->project_board_id)
                    ->where('is_done', $task->done_at !== null)
                    ->orderBy('position')->value('id');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Team-scoped, not user-scoped — see CrmWorkspace::scopeOwnedBy() for
     * the full reasoning (behaviour-neutral today, never falls back to
     * user_id on a null/stale active team).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('tasks.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('tasks.done_at');
    }

    /**
     * Past its time and still not done. A task with no due date can never be
     * overdue — that is the point of leaving the date empty.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('tasks.due_at')->where('tasks.due_at', '<', now());
    }

    /**
     * Due before the end of today, including what is already late — because
     * "today" on a to-do list means "deal with this today".
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('tasks.due_at')
            ->where('tasks.due_at', '<=', now()->endOfDay());
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function projectBoard(): BelongsTo
    {
        return $this->belongsTo(ProjectBoard::class, 'project_board_id');
    }

    public function projectColumn(): BelongsTo
    {
        return $this->belongsTo(ProjectColumn::class, 'project_column_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_task_assignees', 'task_id', 'user_id');
    }

    public function projectLabels(): BelongsToMany
    {
        return $this->belongsToMany(ProjectLabel::class, 'project_task_labels', 'task_id', 'label_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ProjectChecklistItem::class, 'task_id')->orderBy('position')->orderBy('id');
    }

    public function projectEvents(): HasMany
    {
        return $this->hasMany(ProjectTaskEvent::class, 'task_id')->orderByDesc('id');
    }
}
