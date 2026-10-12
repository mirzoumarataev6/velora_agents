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

/** A private folder for one owner's CRM customers and sales records. */
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

    /** @param Builder<CrmWorkspace> $query */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('crm_workspaces.user_id', $user->id);
    }

    public static function defaultFor(User $user): self
    {
        return $user->crmWorkspaces()->firstOrCreate(
            ['name' => '__main__'],
            ['color' => 'blue', 'is_default' => true],
        );
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
