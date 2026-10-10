<?php

namespace App\Models;

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

    public const KINDS = ['note', 'call', 'visit', 'meeting', 'other'];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
        ];
    }

    /**
     * Only this owner's notes. Same convention as every other CRM model,
     * even though a note's own `user_id` is the author, not necessarily the
     * subject's owner — in this product the two are always the same person,
     * since only the owner themselves ever logs an entry.
     *
     * @param  Builder<CrmNote>  $query
     * @return Builder<CrmNote>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('crm_notes.user_id', $user->id);
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
