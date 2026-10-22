<?php

namespace App\Services\Teams;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\DB;

class CreatesTeam
{
    public function create(User $owner, string $name): Team
    {
        $team = DB::transaction(function () use ($owner, $name): Team {
            $personal = Team::whereKey($owner->activeTeamId())
                ->where('created_by', $owner->id)
                ->lockForUpdate()->first();

            if ($personal?->is_personal) {

                $personal->forceFill(['name' => $name, 'is_personal' => false])->save();

                return $personal;
            }

            $team = Team::create(['name' => $name, 'created_by' => $owner->id]);
            $roles = Role::copyTemplatesFor($team);

            TeamMembership::create([
                'team_id' => $team->id,
                'user_id' => $owner->id,
                'role_id' => $roles[PermissionCatalog::OWNER]->id,
                'joined_at' => now(),
            ]);

            $owner->forceFill(['current_team_id' => $team->id])->save();

            return $team;
        });

        $owner->flushPermissionCache();

        return $team;
    }
}
