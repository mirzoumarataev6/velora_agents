<?php

namespace App\Models;

use App\Models\Concerns\HasTeams;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'google_id', 'locale', 'theme', 'lead_notifications_enabled', 'terms_accepted_at', 'terms_version'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, HasTeams, Notifiable;

    protected $attributes = [
        'lead_notifications_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'lead_notifications_enabled' => 'boolean',
            'welcomed_at' => 'datetime',
            'playground_tried_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function awaitsWelcome(): bool
    {
        return $this->welcomed_at === null;
    }

    public function markWelcomed(): void
    {
        if ($this->welcomed_at !== null) {
            return;
        }

        $this->forceFill(['welcomed_at' => now()])->save();
    }

    public function markPlaygroundTried(): void
    {
        if ($this->playground_tried_at !== null) {
            return;
        }

        $this->forceFill(['playground_tried_at' => now()])->save();
    }

    public function dismissOnboarding(): void
    {
        if ($this->onboarding_dismissed_at !== null) {
            return;
        }

        $this->forceFill(['onboarding_dismissed_at' => now()])->save();
    }

    public function markOnboardingCompleted(): void
    {
        if ($this->onboarding_completed_at !== null) {
            return;
        }

        $this->forceFill(['onboarding_completed_at' => now()])->save();
    }

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

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function crmWorkspaces(): HasMany
    {
        return $this->hasMany(CrmWorkspace::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function leads(): HasManyThrough
    {
        return $this->hasManyThrough(Lead::class, Agent::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    public function biSources(): HasMany
    {
        return $this->hasMany(BiSource::class);
    }

    public function biDatasets(): HasMany
    {
        return $this->hasMany(BiDataset::class);
    }

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function activeTeamId(): ?int
    {
        if ($this->current_team_id === null) {
            return null;
        }

        return $this->membershipIn($this->current_team_id) !== null
            ? $this->current_team_id
            : null;
    }
}
