<?php

namespace App\Models;

use App\Policies\ChannelPolicy;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['type', 'status', 'credentials', 'settings'])]
#[UsePolicy(ChannelPolicy::class)]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Channel $channel) {
            if (empty($channel->uuid)) {
                $channel->uuid = (string) Str::uuid();
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Mask a credential value for display, revealing only the last 4 characters.
     */
    public static function maskCredential(?string $value): ?string
    {
        if (empty($value)) {
            return $value;
        }

        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4).substr($value, -4);
    }
}
