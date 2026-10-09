<?php

namespace Database\Factories;

use App\Models\BiConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiConnection>
 */
class BiConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Склад 1С',
            'engine' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'readonly',
            'password' => 'secret',
            'ssl' => false,
            'status' => BiConnection::STATUS_UNVERIFIED,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => BiConnection::STATUS_READY,
            'last_tested_at' => now(),
            'last_error' => null,
        ]);
    }
}
