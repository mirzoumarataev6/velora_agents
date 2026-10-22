<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CustomerPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['crm_workspace_id', 'name', 'phone', 'phone_key', 'source', 'status', 'tags', 'first_contact_at', 'last_contact_at', 'note'])]
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;
    use StampsTeam;

    protected static function booted(): void
    {
        static::deleting(function (Customer $customer) {
            if (! $customer->isForceDeleting()) {
                $customer->forceFill(['phone_key' => null])->saveQuietly();
            }
        });
    }

    public const STATUSES = ['lead', 'active', 'customer', 'lost'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'first_contact_at' => 'datetime',
            'last_contact_at' => 'datetime',
        ];
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('customers.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

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

    public function isChatOnly(): bool
    {
        return blank($this->phone);
    }

    public function displayName(): string
    {
        return filled($this->name) ? $this->name : __('crm.customers.no_name');
    }

    public function maskedPhoneTail(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone) ?? '';

        return $digits === '' ? null : '•••'.substr($digits, -4);
    }

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

    public function notes(): MorphMany
    {
        return $this->morphMany(CrmNote::class, 'notable')->latest('happened_at');
    }
}
