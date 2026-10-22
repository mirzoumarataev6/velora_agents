<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\DealPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['conversation_id', 'agent_id', 'lead_id', 'crm_workspace_id', 'title', 'amount', 'deal_date', 'sales_origin', 'payment_status', 'stage', 'position', 'note', 'tags', 'closed_at', 'cancelled_reason', 'won_reason'])]
#[UsePolicy(DealPolicy::class)]
class Deal extends Model
{
    use HasFactory;
    use SoftDeletes;
    use StampsTeam;

    public const STAGES = ['new_lead', 'haggling', 'payment'];

    public const STAGE_CANCELLED = 'cancelled';

    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];

    public const SALES_ORIGINS = ['social', 'in_person'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'stage_changed_at' => 'datetime',
            'stage_set_by_owner_at' => 'datetime',
            'closed_at' => 'datetime',
            'deal_date' => 'date',
            'tags' => 'array',
        ];
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('deals.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('deals.stage_role')->orWhere('deals.stage_role', '!=', 'lost'))
            ->whereNull('deals.closed_at');
    }

    public function isClosed(): bool
    {
        return $this->stage_role === 'lost' || $this->closed_at !== null;
    }

    public function canBeClosed(): bool
    {
        return $this->stage_role === 'won';
    }

    public function timeInStage(): ?string
    {
        return $this->stage_changed_at?->diffForHumans(null, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CrmWorkspace::class, 'crm_workspace_id');
    }

    public function crmStage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'crm_stage_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DealActivity::class)->latest('created_at');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(CrmNote::class, 'notable')->latest('happened_at');
    }
}
