<?php

namespace App\Models;

use App\Policies\LeadPolicy;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id', 'customer_name', 'phone', 'contact', 'items', 'note', 'source', 'status', 'seen_at', 'details',
])]
#[UsePolicy(LeadPolicy::class)]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'seen_at' => 'datetime',
        ];
    }

    /**
     * No phone number, so this customer can only be reached back through the
     * chat they wrote from.
     *
     * The order was taken anyway — refusing to record it because someone would
     * not leave a number loses the sale — so the owner needs to see at a glance
     * that picking up the phone is not an option here.
     */
    public function isChatOnly(): bool
    {
        return blank($this->phone);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
