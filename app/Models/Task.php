<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\TaskPolicy;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectAccess;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

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

            static::assertColumnHasRoom($task);

            // Read the next rank while the destination column is locked;
            // simultaneous CRM reminders otherwise choose the same rank.
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

            if ($task->isDirty('project_column_id')
                || ($task->isDirty('archived_at') && $task->archived_at === null)
                || ($task->isDirty('deleted_at') && $task->deleted_at === null)) {
                static::assertColumnHasRoom($task);
            }
        });
    }

    private static function assertColumnHasRoom(Task $task): void
    {
        if ($task->project_column_id === null || $task->archived_at !== null) {
            return;
        }

        // Every writer, including CRM reminders and archive restores, must
        // lock the destination before counting. Checking only the Kanban
        // controller let the old CRM route overfill a supposedly hard limit.
        $column = ProjectColumn::whereKey($task->project_column_id)->lockForUpdate()->first();
        // Leave invalid links to the composite FK so every database writer
        // receives the same integrity error, including imports and raw inserts.
        if ($column === null || (int) $column->team_id !== (int) $task->team_id
            || (int) $column->board_id !== (int) $task->project_board_id) {
            return;
        }
        if ($column->is_done || $column->wip_limit === null) {
            return;
        }

        static::assertLockIsLive($column);

        // U-01: a plain COUNT is a consistent (non-locking) read. InnoDB's
        // REPEATABLE READ takes its snapshot at a transaction's FIRST
        // consistent read — if some earlier plain SELECT in this same
        // transaction (e.g. CRM task creation's own lookups before the
        // column lock above) already took that snapshot, this count could
        // still see pre-commit state and undercount occupants a concurrent
        // transaction already committed. lockForUpdate() here forces a
        // locking read instead, which MySQL always resolves against the
        // latest committed data regardless of when the snapshot was taken —
        // the same reason the column lock above means anything.
        $occupants = static::where('project_column_id', $column->id)->whereNull('archived_at')->lockForUpdate();
        if ($task->exists) {
            $occupants->whereKeyNot($task->id);
        }
        if ($occupants->count() >= $column->wip_limit) {
            throw ValidationException::withMessages(['column_id' => __('projects.errors.wip_limit')]);
        }
    }

    /**
     * `lockForUpdate()` above only holds a row lock for the life of an open
     * transaction — outside one, the lock is released the instant that
     * SELECT returns, so two concurrent writers could each pass the
     * occupancy count below before either one's insert/update actually
     * lands, silently turning a hard WIP limit into an advisory one. Every
     * caller that reaches a limited column MUST already be inside
     * DB::transaction() for the lock above to mean anything; this throws
     * loudly the moment that stops being true rather than let the limit
     * quietly become decorative again.
     */
    private static function assertLockIsLive(ProjectColumn $column): void
    {
        if ($column->wip_limit !== null && DB::transactionLevel() === 0) {
            throw new LogicException(
                'Task::assertColumnHasRoom() reached a wip_limit column outside an open transaction. '.
                'Wrap whatever call created or moved this task in DB::transaction() so the row lock above holds.'
            );
        }
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
     * CRM still has direct reminder URLs and deal/customer timelines. A team
     * filter alone would reveal cards from private project boards there.
     * CRM-only roles retain the shared inbox; other boards require project
     * visibility as well as the caller's CRM permission.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeVisibleInCrm(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();
        if ($teamId === null) {
            return $query->whereRaw('1 = 0');
        }

        $inbox = ProjectBoard::query()->where('team_id', $teamId)
            ->whereNull('archived_at')->where('slug', '_inbox')->where('visibility', 'team')
            ->select('id');

        return $query->where('tasks.team_id', $teamId)->whereNull('tasks.archived_at')
            ->where(function (Builder $visible) use ($user, $inbox): void {
                $visible->whereIn('tasks.project_board_id', $inbox);
                if ($user->can(PermissionCatalog::PROJECTS_VIEW)) {
                    $visible->orWhereIn('tasks.project_board_id',
                        app(ProjectAccess::class)->visibleBoards($user)->select('project_boards.id'));
                }
            });
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
