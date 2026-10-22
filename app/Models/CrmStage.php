<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CrmStagePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'color'])]
#[UsePolicy(CrmStagePolicy::class)]
class CrmStage extends Model
{
    use SoftDeletes;
    use StampsTeam;

    public const ROLES = ['initial', 'won', 'lost'];

    public const COLORS = CrmWorkspace::COLORS;

    protected static function booted(): void
    {
        static::saving(function (CrmStage $stage) {
            if ($stage->exists && $stage->isDirty('role')) {
                throw ValidationException::withMessages(['role' => __('crm.stages.role_immutable')]);
            }

            if ($stage->role !== null) {

                $duplicate = $stage->team_id !== null
                    ? static::where('team_id', $stage->team_id)->where('role', $stage->role)->whereKeyNot($stage->id)->exists()
                    : static::where('user_id', $stage->user_id)->where('role', $stage->role)->whereKeyNot($stage->id)->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['role' => __('crm.stages.role_taken')]);
                }
            }
        });

        static::deleting(function (CrmStage $stage) {
            if ($stage->role !== null) {
                throw ValidationException::withMessages(['role' => __('crm.stages.role_stage_undeletable')]);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('crm_stages.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('position is null')->orderBy('position');
    }

    private static function seedDefinitions(): array
    {
        return [
            ['name' => Deal::STAGES[0], 'role' => 'initial', 'position' => 0, 'color' => 'slate'],
            ['name' => Deal::STAGES[1], 'role' => null, 'position' => 1, 'color' => 'amber'],
            ['name' => Deal::STAGES[2], 'role' => 'won', 'position' => 2, 'color' => 'green'],
            ['name' => Deal::STAGE_CANCELLED, 'role' => 'lost', 'position' => null, 'color' => 'rose'],
        ];
    }

    public static function defaultsFor(User $user, Team|int|null $team = null): Collection
    {
        if ($team === null) {

            $existing = static::where('user_id', $user->id)->ordered()->get();

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            foreach (static::seedDefinitions() as $stage) {

                static::forceCreate($stage + ['user_id' => $user->id, 'is_system' => true]);
            }

            return static::where('user_id', $user->id)->ordered()->get();
        }

        $teamId = $team instanceof Team ? $team->id : $team;

        $existing = static::where('team_id', $teamId)->ordered()->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        foreach (static::seedDefinitions() as $stage) {

            static::forceCreate($stage + ['user_id' => $user->id, 'is_system' => true, 'team_id' => $teamId]);
        }

        return static::where('team_id', $teamId)->ordered()->get();
    }

    public static function initialFor(User $user, Team|int|null $team = null): CrmStage
    {
        return static::defaultsFor($user, $team)->firstWhere('role', 'initial');
    }

    public static function wonFor(User $user, Team|int|null $team = null): CrmStage
    {
        return static::defaultsFor($user, $team)->firstWhere('role', 'won');
    }

    public static function lostFor(User $user, Team|int|null $team = null): CrmStage
    {
        return static::defaultsFor($user, $team)->firstWhere('role', 'lost');
    }

    public function displayName(): string
    {
        return $this->is_system ? __('crm.stages.'.$this->name) : $this->name;
    }

    public static function intermediatesFor(User $user, Team|int|null $team = null): Collection
    {
        return static::defaultsFor($user, $team)->filter(fn (CrmStage $stage) => $stage->role === null)->values();
    }

    public static function dotClass(string $color): string
    {
        return match ($color) {
            'violet' => 'bg-purple-500',
            'green' => 'bg-green-500',
            'amber' => 'bg-amber-500',
            'rose' => 'bg-rose-500',
            'slate' => 'bg-slate-500',
            default => 'bg-brand',
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'crm_stage_id');
    }
}
