<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Observers\ConversationObserver;
use App\Policies\ConversationPolicy;
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
    use HasFactory;
    use ScopesActiveTeam;
    use StampsTeam;

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'operator_read_at' => 'datetime',
            'needs_human_at' => 'datetime',
        ];
    }

    public function scopeNeedsHumanUnattended(Builder $query): Builder
    {
        return $query->whereNotNull('conversations.needs_human_at')
            ->where(fn ($w) => $w
                ->whereNull('conversations.operator_read_at')
                ->orWhereColumn('conversations.operator_read_at', '<', 'conversations.needs_human_at')
            );
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

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
