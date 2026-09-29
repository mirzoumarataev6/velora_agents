<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'phone' => null,
            'phone_key' => null,
            'source' => fake()->randomElement(['telegram', 'whatsapp', 'instagram']),
            'status' => 'lead',
            'tags' => null,
            'first_contact_at' => now()->subDays(3),
            'last_contact_at' => now(),
            'note' => null,
        ];
    }

    /** A customer who left a number, and is therefore mergeable across channels. */
    public function withPhone(string $phone = '+992900112233'): static
    {
        return $this->state(fn () => [
            'phone' => $phone,
            'phone_key' => substr(preg_replace('/\D/', '', $phone) ?? '', -9),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
