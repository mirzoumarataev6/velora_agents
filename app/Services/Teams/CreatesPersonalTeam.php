<?php

namespace App\Services\Teams;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;

/**
 * The one place that defines "what a personal team looks like" — used by
 * the one-off backfill command for existing users, and reused unchanged by
 * the registration flow for new users once that is wired up, so the two
 * never drift into creating teams shaped differently from each other.
 */
class CreatesPersonalTeam
{
    public function create(User $user): Team
    {
        $team = Team::create([
            'name' => $this->defaultName($user),
            'created_by' => $user->id,
            'is_personal' => true,
        ]);

        $roles = Role::copyTemplatesFor($team);

        TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role_id' => $roles[PermissionCatalog::OWNER]->id,
            'joined_at' => $user->created_at ?? now(),
        ]);

        return $team;
    }

    private function defaultName(User $user): string
    {
        $name = trim($user->name ?? '');

        return $name !== '' ? "{$name}'s Team" : 'My Team';
    }
}
