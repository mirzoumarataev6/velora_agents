<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\AgentPolicy;
use App\Services\Haggling\HagglingService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'slug', 'avatar', 'description', 'status', 'tone',
    'response_format', 'custom_system_prompt', 'rules_instructions', 'behavior', 'proactivity',
    'haggling_enabled', 'haggling_level', 'haggle_min_price_percent', 'haggling_instructions',
])]
#[UsePolicy(AgentPolicy::class)]
class Agent extends Model
{
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

    public function dataset(): HasOne
    {
        return $this->hasOne(Dataset::class);
    }

    public function databaseConnection(): HasOne
    {
        return $this->hasOne(DatabaseConnection::class);
    }

    public function hagglesOnPrice(): bool
    {
        return (bool) $this->haggling_enabled;
    }

    public function hagglingLevel(): string
    {
        return in_array($this->haggling_level, HagglingService::LEVELS, true)
            ? $this->haggling_level
            : HagglingService::DEFAULT_LEVEL;
    }

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

    public function notifiesOwnerViaTelegram(): bool
    {
        return ! empty($this->notify_telegram_chat_id);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }
}
