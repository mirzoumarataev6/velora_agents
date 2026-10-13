<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\AgentPolicy;
use App\Services\Haggling\HagglingService;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * `language`, `capture_leads`, `humanize_replies` and `transcribe_voice` are
 * deliberately absent. They were owner-facing toggles; they are now always-on
 * behaviour (language is detected per message, orders are always collected,
 * replies are always humanized, voice is always transcribed). The columns
 * survive for backward compatibility, but nothing may write them again — an
 * absent fillable is what stops a stray form field from reintroducing an
 * "off" that no code path reads any more.
 */
#[Fillable([
    'name', 'slug', 'avatar', 'description', 'status', 'tone',
    'response_format', 'custom_system_prompt', 'rules_instructions', 'behavior', 'proactivity',
    'haggling_enabled', 'haggling_level', 'haggle_min_price_percent', 'haggling_instructions',
])]
#[UsePolicy(AgentPolicy::class)]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use ScopesActiveTeam;
    use StampsTeam;

    protected function casts(): array
    {
        return [
            'response_format' => 'array',
            'behavior' => 'array',
            'haggling_enabled' => 'boolean',
            'haggle_min_price_percent' => 'integer',
            'notify_telegram_linked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Agent $agent) {
            if (empty($agent->slug)) {
                $agent->slug = static::uniqueSlug($agent->name);
            }
        });
    }

    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::random(8);
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    /**
     * The agent's structured product catalog (at most one). Separate from the
     * knowledge base: this is queried exactly, not semantically.
     */
    public function dataset(): HasOne
    {
        return $this->hasOne(Dataset::class);
    }

    /**
     * The agent's live connection to the business's own product database (at
     * most one). The advanced alternative to `dataset` — which of the two is
     * actually consulted is `product_source`, never "whichever exists".
     *
     * Deliberately NOT fillable: the source is switched through its own guarded
     * endpoint, so a stray field on the agent form can never silently point the
     * agent at a different set of prices.
     */
    public function databaseConnection(): HasOne
    {
        return $this->hasOne(DatabaseConnection::class);
    }

    /**
     * Whether this agent is allowed to negotiate on price at all.
     *
     * Negotiating never means conceding: even with this on, a below-floor offer
     * goes to the owner for approval. See HagglingService.
     */
    public function hagglesOnPrice(): bool
    {
        return (bool) $this->haggling_enabled;
    }

    /** The conversational style the negotiation is played in. */
    public function hagglingLevel(): string
    {
        return in_array($this->haggling_level, HagglingService::LEVELS, true)
            ? $this->haggling_level
            : HagglingService::DEFAULT_LEVEL;
    }

    /**
     * The lowest price this agent may confirm on its own for a product listed
     * at $listed — the owner's floor, expressed as a percentage of the list
     * price so one setting covers a whole catalog.
     *
     * 100 (the default) means "no discount at all without me".
     */
    public function minimumPriceFor(float $listed): float
    {
        $percent = (int) ($this->haggle_min_price_percent ?? 100);
        $percent = max(1, min(100, $percent));

        return round($listed * $percent / 100, 2);
    }

    public function usesLiveDatabase(): bool
    {
        return $this->product_source === 'database';
    }

    public function datasetProducts(): HasMany
    {
        return $this->hasMany(DatasetProduct::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Whether the owner has linked a personal Telegram chat to receive new-lead
     * notifications for this agent.
     */
    public function notifiesOwnerViaTelegram(): bool
    {
        return ! empty($this->notify_telegram_chat_id);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }
}
