<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CustomerPolicy;
use Database\Factories\CustomerFactory;
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
 * A person the business talks to, independent of which app they happened to
 * write from. Their threads hang off this row; see CustomerResolver for how a
 * thread is decided to belong here.
 */
#[Fillable(['crm_workspace_id', 'name', 'phone', 'phone_key', 'source', 'status', 'tags', 'first_contact_at', 'last_contact_at', 'note'])]
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;
    use StampsTeam;

    /**
     * Frees the phone number the instant a profile is soft-deleted, not only
     * on the hard-delete path the unique index itself would catch.
     *
     * The index on (user_id, phone_key) is a real DB constraint that knows
     * nothing about `deleted_at` — a soft-deleted row still occupies its slot
     * in it. Nulling phone_key here, every time, regardless of which code
     * path deleted the row (today only CustomerResolver::merge(), but this
     * must hold for any future one too), is what makes "a soft-deleted
     * customer never blocks a new one with the same number" true by
     * construction rather than by every caller remembering to do it. The
     * original number survives on `phone` (never unique-constrained) for
     * CustomerResolver::undoMerge() to recompute the key from on restore.
     */
    protected static function booted(): void
    {
        static::deleting(function (Customer $customer) {
            if (! $customer->isForceDeleting()) {
                $customer->forceFill(['phone_key' => null])->saveQuietly();
            }
        });
    }

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
     * Only this team's customers. Every CRM query starts here, so a forgotten
     * where clause cannot leak one account's book into another's.
     *
     * Team-scoped, not user-scoped — see CrmWorkspace::scopeOwnedBy() for
     * the full reasoning (behaviour-neutral today, never falls back to
     * user_id on a null/stale active team).
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('customers.team_id', $teamId)
            : $query->whereRaw('1 = 0');
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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(CrmWorkspace::class, 'crm_workspace_id');
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

    /** The notes/activity thread — newest first, same as the activity log it doubles as. */
    public function notes(): MorphMany
    {
        return $this->morphMany(CrmNote::class, 'notable')->latest('happened_at');
    }
}
