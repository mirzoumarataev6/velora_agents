<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    protected $model = TeamMembership::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'joined_at' => now(),
        ];
    }
}
