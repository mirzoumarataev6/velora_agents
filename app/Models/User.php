<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasTeams;
use Database\Factories\UserFactory;
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
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable;

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
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'lead_notifications_enabled' => 'boolean',
            'welcomed_at' => 'datetime',
            'playground_tried_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * Whether the "Welcome to Velora" moment is still owed to this account.
     *
     * Deliberately a stored column rather than a redirect flash: registration
     * is not the only way an account comes into existence — Google sign-in
     * creates one on an entirely different code path — and a flash would also
     * be spent by any redirect that happens to land first.
     */
    public function awaitsWelcome(): bool
    {
        return $this->welcomed_at === null;
    }

    /**
     * Record that the welcome has been shown. Idempotent, so the view can call
     * it unconditionally while rendering.
     */
    public function markWelcomed(): void
    {
        if ($this->welcomed_at !== null) {
            return;
        }

        $this->forceFill(['welcomed_at' => now()])->save();
    }

    /**
     * Called when the owner sends their first sandbox message. Guarded so the
     * hot path costs a comparison rather than a write on every later message.
     */
    public function markPlaygroundTried(): void
    {
        if ($this->playground_tried_at !== null) {
            return;
        }

        $this->forceFill(['playground_tried_at' => now()])->save();
    }

    /** "Not now." Permanent, and beats every other onboarding state. */
    public function dismissOnboarding(): void
    {
        if ($this->onboarding_dismissed_at !== null) {
            return;
        }

        $this->forceFill(['onboarding_dismissed_at' => now()])->save();
    }

    /**
     * Stamped by the request that renders the finished checklist, so the
     * congratulations panel is shown once and the card is gone on the next
     * load — same one-shot mechanism as the welcome.
     */
    public function markOnboardingCompleted(): void
    {
        if ($this->onboarding_completed_at !== null) {
            return;
        }

        $this->forceFill(['onboarding_completed_at' => now()])->save();
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

    /**
     * The CRM's customer book. Hangs off the owner, not off an agent: the same
     * person writing to two of the owner's agents is one customer.
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** The sales pipeline. Like customers, it belongs to the owner, not an agent. */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** CRM folders are owner-owned, independently of any agent. */
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

    /**
     * The BI module's uploaded files and live connections. Like customers and
     * deals, these hang off the owner rather than off an agent: a spreadsheet of
     * last quarter's sales belongs to the business, not to whichever assistant
     * happens to exist.
     */
    public function biSources(): HasMany
    {
        return $this->hasMany(BiSource::class);
    }

    /**
     * Every queryable dataset this account holds, across all its sources.
     *
     * Hangs off the user directly (rather than through the source) because
     * `bi_datasets.user_id` is denormalized for exactly this: quota counts and
     * scope checks read it without a join.
     */
    public function biDatasets(): HasMany
    {
        return $this->hasMany(BiDataset::class);
    }

    /**
     * Navigation state only — see `users.current_team_id`'s migration
     * comment. Not yet read by anything: Stage 1 of the team migration is
     * schema-only.
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * The team CRM reads/writes should scope to right now — `current_team_id`
     * itself, but only once confirmed to still be a team this user actually
     * belongs to (a stale pointer left over from, say, being removed from a
     * team must not resolve to it). Null for both "no current team" and "not
     * a member of it any more" — callers treat the two identically, since
     * the only safe thing to show for either is nothing.
     *
     * Routes through `membershipIn()`, which HasTeams already memoizes per
     * instance, so asking this more than once per request (every
     * `scopeOwnedBy()` call on this request's $request->user()) costs at
     * most the one query that method already pays for.
     */
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
