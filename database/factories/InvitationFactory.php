<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role_id' => Role::factory(),
            'invited_by' => User::factory(),
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ];
    }
}
