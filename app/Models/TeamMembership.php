<?php

namespace App\Models;

use Database\Factories\TeamMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (team, user): the user's membership and role on that team.
 * Lives in `team_user` rather than `team_memberships` so a future
 * `$team->users()` belongsToMany relation follows Eloquent's pivot-table
 * naming convention; this is a full model, not a bare pivot, because
 * membership carries its own state (`role_id`, `joined_at`) beyond a plain
 * join row.
 */
#[Table('team_user')]
#[Fillable(['team_id', 'user_id', 'role_id', 'joined_at'])]
class TeamMembership extends Model
{
    /** @use HasFactory<TeamMembershipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
