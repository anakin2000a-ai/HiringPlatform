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
            'franchise_account_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'viewer',
            'access_scope' => 'store',
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function franchiseAdmin(): static
    {
        return $this->state([
            'role' => 'franchise_admin',
            'access_scope' => 'franchise',
        ]);
    }

    public function storeManager(): static
    {
        return $this->state([
            'role' => 'store_manager',
            'access_scope' => 'store',
        ]);
    }

    public function recruiter(): static
    {
        return $this->state([
            'role' => 'recruiter',
            'access_scope' => 'store',
        ]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
