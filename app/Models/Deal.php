<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\DealPolicy;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing the business is trying to sell to one customer.
 *
 * Оплата (the WON-role stage) means the money actually arrived — not "an
 * order was placed" and not "a price was agreed", both of which used to be
 * enough to land a deal here. Nothing this system tracks can observe a real
 * payment, so a deal only ever reaches the WON stage by the owner's own hand
 * (see DealStageAutomation for exactly which signals are still allowed to
 * move a deal automatically, and why WON is not one of their destinations
 * any more).
 *
 * The pipeline itself — which stages exist, their order, their names — is
 * now the owner's own `crm_stages` (see that model's docblock). Everything
 * on THIS model that used to key off a literal stage string now keys off
 * `stage_role` instead, a value denormalized from the deal's `crmStage`
 * every time it changes — see DealBoard::move() and DealStageAutomation for
 * the write side of that invariant, and CrmStageSyncTest for the test
 * pinning it.
 */
#[Fillable(['conversation_id', 'agent_id', 'lead_id', 'crm_workspace_id', 'title', 'amount', 'deal_date', 'sales_origin', 'payment_status', 'stage', 'position', 'note', 'tags', 'closed_at', 'cancelled_reason', 'won_reason'])]
#[UsePolicy(DealPolicy::class)]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use SoftDeletes;
    use StampsTeam;

    /** The names CrmStage::defaultsFor() seeds a fresh pipeline with — not a universal list any more, see that method. */
    public const STAGES = ['new_lead', 'haggling', 'payment'];

    /** The lost-role default's name — kept as a constant for the same reason STAGES is: DemoSeeder and tests still build fixtures against the default pipeline. */
    public const STAGE_CANCELLED = 'cancelled';

    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];

    /** How the purchase happened; deliberately independent from chat channel. */
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

    /**
     * Team-scoped, not user-scoped — see CrmWorkspace::scopeOwnedBy() for
     * the full reasoning (behaviour-neutral today, never falls back to
     * user_id on a null/stale active team).
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('deals.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    /**
     * Still in play. What the automation is allowed to touch and what "this
     * customer already has a deal open" means.
     *
     * Not just "not lost": a deal marked Закрыто is also finished, even
     * though the WON stage itself is still a normal, filterable stage —
     * closed_at is the thing that actually ends a deal's life now, landing
     * in the LOST stage being the other way one can end. `stage_role` can
     * legitimately be null here (a deal whose stage was deleted and not yet
     * reassigned, mid-transaction) — null is treated as "not lost", the
     * same as any non-lost role, rather than silently excluded.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('deals.stage_role')->orWhere('deals.stage_role', '!=', 'lost'))
            ->whereNull('deals.closed_at');
    }

    public function isClosed(): bool
    {
        return $this->stage_role === 'lost' || $this->closed_at !== null;
    }

    /**
     * Whether "Закрыто" even makes sense here. Restricted to the WON stage:
     * closing a deal is the owner attesting the sale is fully done, which
     * requires having said it was paid first — a deal cannot be finished on
     * a question that has not been answered yet.
     */
    public function canBeClosed(): bool
    {
        return $this->stage_role === 'won';
    }

    /** How long this card has sat where it is — the stalled-deal signal. */
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

    /** The notes/activity thread — newest first, same as the activity log it doubles as. */
    public function notes(): MorphMany
    {
        return $this->morphMany(CrmNote::class, 'notable')->latest('happened_at');
    }
}
