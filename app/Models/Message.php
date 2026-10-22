<?php

namespace App\Models;

use App\Policies\MessagePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'external_message_id', 'meta', 'source_type', 'voice_duration'])]
#[UsePolicy(MessagePolicy::class)]
class Message extends Model
{
    use HasFactory;

    public const RATING_UP = 'thumbs_up';

    public const RATING_DOWN = 'thumbs_down';

    public const RATINGS = [self::RATING_UP, self::RATING_DOWN];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'rated_at' => 'datetime',
        ];
    }

    public function rate(string $rating): bool
    {
        if (! in_array($rating, self::RATINGS, true)) {
            return false;
        }

        $recorded = static::query()
            ->whereKey($this->getKey())
            ->whereNull('rating')
            ->update(['rating' => $rating, 'rated_at' => now()]);

        if ($recorded === 1) {
            $this->forceFill(['rating' => $rating, 'rated_at' => now()])->syncOriginal();
        }

        return $recorded === 1;
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
