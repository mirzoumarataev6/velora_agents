<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\TaskPolicy;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
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
}
