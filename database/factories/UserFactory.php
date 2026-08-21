<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // A fixture is an established account, not a fresh sign-up — the
            // same stance the onboarding migration takes towards rows that
            // already existed. Without this, every test that renders a
            // dashboard page would also raise the welcome modal and the
            // first-run checklist over whatever it was actually asserting.
            // Tests about onboarding opt in with newcomer() below.
            'welcomed_at' => now(),
            'onboarding_dismissed_at' => now(),
        ];
    }

    /**
     * An account that has just been created and has seen nothing yet.
     */
    public function newcomer(): static
    {
        return $this->state(fn (array $attributes) => [
            'welcomed_at' => null,
            'playground_tried_at' => null,
            'onboarding_dismissed_at' => null,
            'onboarding_completed_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
