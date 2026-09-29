<?php

namespace App\Models;

use App\Policies\DealPolicy;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the business is trying to sell to one customer.
 *
 * Оплата means the money actually arrived — not "an order was placed" and
 * not "a price was agreed", both of which used to be enough to land a deal
 * here. Nothing this system tracks can observe a real payment, so a deal
 * only ever reaches Оплата by the owner's own hand (see DealStageAutomation
 * for exactly which signals are still allowed to move a deal automatically,
 * and why Оплата is not one of their destinations any more).
 */
#[Fillable(['conversation_id', 'agent_id', 'lead_id', 'title', 'amount', 'deal_date', 'payment_status', 'stage', 'position', 'note', 'closed_at', 'cancelled_reason'])]
#[UsePolicy(DealPolicy::class)]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    /**
     * The pipeline, left to right. Three stages on purpose: Новый лид (a
     * thread exists), Торг (the customer and the price are actually being
     * discussed), Оплата (the money has arrived — see the class docblock
     * below on why that last one is manual-only). Order matters here: it is
     * both the list's stage-filter order and what "forward only" means to
     * the automation.
     */
    public const STAGES = ['new_lead', 'haggling', 'payment'];

    /**
     * Set apart from the flow rather than appended to it. A loss is not the
     * last step of a sale, and a column of dead deals sitting at the end of the
     * board is the fastest way to stop reading the board — but hiding losses
     * entirely is how a business never finds out why it loses.
     */
    public const STAGE_CANCELLED = 'cancelled';

    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];

    /** Every stage the list can be filtered by, including the set-apart one. */
    public static function allStages(): array
    {
        return array_merge(self::STAGES, [self::STAGE_CANCELLED]);
    }

    /** The badge colour for a stage's pill in the list. */
    public static function badgeClass(string $stage): string
    {
        return match ($stage) {
            'new_lead' => 'badge-neutral',
            'haggling' => 'badge-warning',
            'payment' => 'badge-success',
            self::STAGE_CANCELLED => 'badge-danger',
            default => 'badge-neutral',
        };
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'stage_changed_at' => 'datetime',
            'stage_set_by_owner_at' => 'datetime',
            'closed_at' => 'datetime',
            'deal_date' => 'date',
        ];
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('deals.user_id', $user->id);
    }

    /**
     * Still in play. What the automation is allowed to touch and what "this
     * customer already has a deal open" means.
     *
     * Not just "not cancelled": a deal marked Закрыто is also finished, even
     * though Оплата itself is still a normal, filterable stage — closed_at is
     * the thing that actually ends a deal's life now, cancellation being the
     * other way one can end.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('deals.stage', '!=', self::STAGE_CANCELLED)->whereNull('deals.closed_at');
    }

    /** How far along the pipeline a stage is; -1 for the cancelled bucket. */
    public static function stageIndex(string $stage): int
    {
        $index = array_search($stage, self::STAGES, true);

        return $index === false ? -1 : $index;
    }

    public function isClosed(): bool
    {
        return $this->stage === self::STAGE_CANCELLED || $this->closed_at !== null;
    }

    /**
     * Whether "Закрыто" even makes sense here. Restricted to Оплата: closing
     * a deal is the owner attesting the sale is fully done, which requires
     * having said it was paid first — a deal cannot be finished on a
     * question that has not been answered yet.
     */
    public function canBeClosed(): bool
    {
        return $this->stage === 'payment';
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
}
