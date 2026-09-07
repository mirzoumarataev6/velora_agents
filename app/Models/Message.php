<?php

namespace App\Models;

use App\Policies\MessagePolicy;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'external_message_id', 'meta', 'source_type', 'voice_duration'])]
#[UsePolicy(MessagePolicy::class)]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /** The customer liked this reply. */
    public const RATING_UP = 'thumbs_up';

    /** The customer did not. The whole feature exists for this value. */
    public const RATING_DOWN = 'thumbs_down';

    /** @var array<int, string> */
    public const RATINGS = [self::RATING_UP, self::RATING_DOWN];

    /**
     * Deliberately absent from the Fillable list above: a rating is never part
     * of a mass-assigned payload. It is written once, by `rate()`, through a
     * conditional UPDATE — see there for why that matters.
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'rated_at' => 'datetime',
        ];
    }

    /**
     * Record the customer's verdict, once.
     *
     * The `whereNull` is the double-vote guard, and it lives in the WHERE
     * clause rather than in a read-then-write because two taps on the same
     * inline button arrive as two webhook deliveries that can be handled
     * concurrently. A check in PHP would let both pass; this lets exactly one
     * row update, and the affected-row count tells the caller which tap it was
     * handling.
     *
     * @return bool Whether THIS call is the one that recorded the vote.
     */
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
