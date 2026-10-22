<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Observers\LeadObserver;
use App\Policies\LeadPolicy;
use App\Services\Haggling\HagglingService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id', 'customer_id', 'type', 'customer_name', 'phone', 'contact', 'items', 'note', 'source', 'status', 'seen_at',
    'details', 'requested_price', 'listed_price', 'approved_price', 'negotiation_summary',
])]
#[UsePolicy(LeadPolicy::class)]
#[ObservedBy(LeadObserver::class)]
class Lead extends Model
{
    use HasFactory;
    use ScopesActiveTeam;
    use StampsTeam;

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'seen_at' => 'datetime',
            'requested_price' => 'decimal:2',
            'listed_price' => 'decimal:2',
            'approved_price' => 'decimal:2',
        ];
    }

    public function isChatOnly(): bool
    {
        return blank($this->phone);
    }

    public function isHaggle(): bool
    {
        return $this->type === HagglingService::TYPE;
    }

    public function awaitsPriceApproval(): bool
    {
        return $this->isHaggle() && $this->status === HagglingService::STATUS_PENDING;
    }

    public function requestedDiscountPercent(): ?int
    {
        $listed = (float) $this->listed_price;
        $requested = (float) $this->requested_price;

        if ($listed <= 0 || $requested <= 0 || $requested >= $listed) {
            return null;
        }

        return (int) round(($listed - $requested) / $listed * 100);
    }

    public static function formatPrice(mixed $value): ?string
    {
        $value = (float) $value;

        return $value > 0 ? rtrim(rtrim(number_format($value, 2, '.', ' '), '0'), '.') : null;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
