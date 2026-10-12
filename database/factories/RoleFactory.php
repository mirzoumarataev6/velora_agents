<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->jobTitle(),
            'slug' => fake()->unique()->slug(2),
        ];
    }
}
