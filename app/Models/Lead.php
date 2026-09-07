<?php

namespace App\Models;

use App\Policies\LeadPolicy;
use App\Services\Haggling\HagglingService;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id', 'type', 'customer_name', 'phone', 'contact', 'items', 'note', 'source', 'status', 'seen_at',
    'details', 'requested_price', 'listed_price', 'approved_price', 'negotiation_summary',
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
            'requested_price' => 'decimal:2',
            'listed_price' => 'decimal:2',
            'approved_price' => 'decimal:2',
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

    /**
     * A price the customer asked for and the assistant was not allowed to give,
     * waiting on the owner. Rendered with approve/decline actions instead of the
     * ordinary status dropdown.
     */
    public function isHaggle(): bool
    {
        return $this->type === HagglingService::TYPE;
    }

    /** Still waiting on the owner — the only state the actions are offered in. */
    public function awaitsPriceApproval(): bool
    {
        return $this->isHaggle() && $this->status === HagglingService::STATUS_PENDING;
    }

    /** How deep a cut the customer is asking for, as a percentage, when both prices are known. */
    public function requestedDiscountPercent(): ?int
    {
        $listed = (float) $this->listed_price;
        $requested = (float) $this->requested_price;

        if ($listed <= 0 || $requested <= 0 || $requested >= $listed) {
            return null;
        }

        return (int) round(($listed - $requested) / $listed * 100);
    }

    /**
     * A price the way every surface shows it — the owner's panel, the Telegram
     * alert and the sentence relayed to the customer. One implementation so the
     * three can never disagree about what "1500" looks like.
     *
     * No currency symbol: the catalog's currency is not modelled, and inventing
     * one would be wrong for most of these shops.
     */
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
}
