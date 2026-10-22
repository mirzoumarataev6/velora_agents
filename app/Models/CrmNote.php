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
