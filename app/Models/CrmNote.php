<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CrmNotePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One entry in a deal's or customer's notes/activity thread — a free-text
 * note or a manually logged call/visit/meeting. `user_id` is deliberately
 * excluded from mass assignment; only forceFill() may set the author.
 */
#[Fillable(['notable_type', 'notable_id', 'kind', 'body', 'happened_at'])]
#[UsePolicy(CrmNotePolicy::class)]
class CrmNote extends Model
{
    use SoftDeletes;
    use StampsTeam;

    public const KINDS = ['note', 'call', 'visit', 'meeting', 'other'];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
        ];
    }

    /**
     * Only this team's notes. Same convention as every other CRM model,
     * even though a note's own `user_id` is the author, not necessarily the
     * subject's owner — once a team has several members the two can differ
     * (a teammate logging a call on the owner's customer), which is exactly
     * why this scope reads `team_id` and not `user_id`.
     *
     * Team-scoped, not user-scoped — see CrmWorkspace::scopeOwnedBy() for
     * the full reasoning (behaviour-neutral today, never falls back to
     * user_id on a null/stale active team).
     *
     * @param  Builder<CrmNote>  $query
     * @return Builder<CrmNote>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('crm_notes.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
