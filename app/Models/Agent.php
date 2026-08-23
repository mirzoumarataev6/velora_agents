<?php

namespace App\Models;

use App\Policies\AgentPolicy;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'slug', 'avatar', 'description', 'status', 'language', 'tone',
    'response_format', 'custom_system_prompt', 'rules_instructions', 'behavior', 'capture_leads', 'proactivity',
    'humanize_replies', 'transcribe_voice',
])]
#[UsePolicy(AgentPolicy::class)]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'response_format' => 'array',
            'behavior' => 'array',
            'capture_leads' => 'boolean',
            'humanize_replies' => 'boolean',
            'transcribe_voice' => 'boolean',
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
