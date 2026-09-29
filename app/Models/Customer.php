<?php

namespace App\Models;

use App\Policies\CustomerPolicy;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person the business talks to, independent of which app they happened to
 * write from. Their threads hang off this row; see CustomerResolver for how a
 * thread is decided to belong here.
 */
#[Fillable(['name', 'phone', 'phone_key', 'source', 'status', 'tags', 'first_contact_at', 'last_contact_at', 'note'])]
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Where the customer stands with the business. Deliberately the owner's
     * judgement and nothing else: no signal in this system can tell an "active"
     * conversation from a polite brush-off, so nothing moves this automatically.
     */
    public const STATUSES = ['lead', 'active', 'customer', 'lost'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'first_contact_at' => 'datetime',
            'last_contact_at' => 'datetime',
        ];
    }

    /**
     * Only this owner's customers. Every CRM query starts here, so a forgotten
     * where clause cannot leak one account's book into another's.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('customers.user_id', $user->id);
    }

    /**
     * Matches name, either spelling of the phone number, and the tag list —
     * the four things an owner actually gropes for someone by.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D/', '', $term) ?? '';

        return $query->where(fn ($w) => $w
            ->where('customers.name', 'like', "%{$term}%")
            ->orWhere('customers.phone', 'like', "%{$term}%")
            ->orWhere('customers.tags', 'like', "%{$term}%")
            ->when($digits !== '', fn ($q) => $q->orWhere('customers.phone_key', 'like', "%{$digits}%"))
        );
    }

    /** Whether the business can ring this person, or only write back in-chat. */
    public function isChatOnly(): bool
    {
        return blank($this->phone);
    }

    /** The name to print when the customer never gave one. */
    public function displayName(): string
    {
        return filled($this->name) ? $this->name : __('crm.customers.no_name');
    }

    /**
     * Every channel type this person has written from, which is the visible
     * pay-off of the merge: two icons here means two threads became one profile.
     *
     * @return list<string>
     */
    public function channelTypes(): array
    {
        return $this->conversations
            ->map(fn (Conversation $c) => $c->channel?->type)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
