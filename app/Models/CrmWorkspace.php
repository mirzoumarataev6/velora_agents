<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CrmWorkspacePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'color'])]
#[UsePolicy(CrmWorkspacePolicy::class)]
class CrmWorkspace extends Model
{
    use SoftDeletes;
    use StampsTeam;

    public const COLORS = ['blue', 'violet', 'green', 'amber', 'rose', 'slate'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('crm_workspaces.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    public static function defaultFor(User $user, Team|int|null $team = null): self
    {
        if ($team === null) {
            return $user->crmWorkspaces()->firstOrCreate(
                ['name' => '__main__'],
                ['color' => 'blue', 'is_default' => true],
            );
        }

        $teamId = $team instanceof Team ? $team->id : $team;

        $existing = static::where('team_id', $teamId)->where('name', '__main__')->first();
        if ($existing !== null) {
            return $existing;
        }

        $legacy = $user->crmWorkspaces()->whereNull('team_id')->where('name', '__main__')->first();
        if ($legacy !== null) {
            $legacy->forceFill(['team_id' => $teamId])->save();

            return $legacy;
        }

        return static::unguarded(fn () => static::firstOrCreate(
            ['team_id' => $teamId, 'name' => '__main__'],
            ['user_id' => $user->id, 'color' => 'blue', 'is_default' => true],
        ));
    }

    public function displayName(): string
    {
        return $this->is_default ? __('crm.workspaces.default_name') : $this->name;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'crm_workspace_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'crm_workspace_id');
    }
}
