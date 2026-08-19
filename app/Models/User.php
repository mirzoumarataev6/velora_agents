<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'google_id', 'locale', 'theme', 'lead_notifications_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mirrors the column default so a User that hasn't been round-tripped
     * through the database still reports alerts as on, rather than as null —
     * which reads as "muted" to every caller that checks it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lead_notifications_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'lead_notifications_enabled' => 'boolean',
        ];
    }

    /**
     * The one or two letters shown in place of an avatar. Derived rather than
     * stored so a rename can never leave a stale monogram behind.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return mb_strtoupper(mb_substr((string) $this->email, 0, 1)) ?: '?';
        }

        $letters = mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr($words[1], 0, 1) : '');

        return mb_strtoupper($letters);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function leads(): HasManyThrough
    {
        return $this->hasManyThrough(Lead::class, Agent::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }
}
