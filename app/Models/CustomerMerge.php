<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'team_id', 'from_customer_id', 'into_customer_id', 'moved_conversation_ids', 'moved_lead_ids', 'moved_deal_ids', 'moved_task_ids', 'undone_at'])]
class CustomerMerge extends Model
{
    use StampsTeam;

    public $timestamps = false;

    public const UNDO_WINDOW_HOURS = 24;

    protected function casts(): array
    {
        return [
            'moved_conversation_ids' => 'array',
            'moved_lead_ids' => 'array',
            'moved_deal_ids' => 'array',
            'moved_task_ids' => 'array',
            'created_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function scopeUndoable(Builder $query): Builder
    {
        return $query->whereNull('undone_at')
            ->where('created_at', '>=', now()->subHours(self::UNDO_WINDOW_HOURS));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'from_customer_id')->withTrashed();
    }

    public function intoCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'into_customer_id');
    }
}
