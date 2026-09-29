<?php

namespace App\Models;

use App\Observers\ConversationObserver;
use App\Policies\ConversationPolicy;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['channel_id', 'customer_id', 'external_user_id', 'external_user_name', 'status', 'last_message_at', 'operator_read_at'])]
#[UsePolicy(ConversationPolicy::class)]
#[ObservedBy(ConversationObserver::class)]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'operator_read_at' => 'datetime',
            'needs_human_at' => 'datetime',
        ];
    }

    /**
     * Escalated to a human and not looked at since.
     *
     * Opening the thread is what clears it (that is what writes
     * operator_read_at), so the bell empties by reading the conversation rather
     * than by dismissing a notification and forgetting about it.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeNeedsHumanUnattended(Builder $query): Builder
    {
        return $query->whereNotNull('conversations.needs_human_at')
            ->where(fn ($w) => $w
                ->whereNull('conversations.operator_read_at')
                ->orWhereColumn('conversations.operator_read_at', '<', 'conversations.needs_human_at')
            );
    }

    /**
     * Leads captured out of this conversation. A conversation can produce more
     * than one — a customer who orders twice is still one conversation.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Only the conversations belonging to agents this user owns. Every inbox
     * query starts here, so cross-tenant leakage can't happen by forgetting a
     * where clause on one code path.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereIn('conversations.agent_id', $user->agents()->select('id'));
    }

    /**
     * A customer message has arrived since the owner last opened this thread.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereHas('messages', function ($q) {
            $q->where('messages.role', 'user')
                ->where(fn ($w) => $w
                    ->whereNull('conversations.operator_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversations.operator_read_at')
                );
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * The person behind this thread. Nullable: playground threads have none,
     * and a thread whose customer profile was deleted keeps its transcript.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
