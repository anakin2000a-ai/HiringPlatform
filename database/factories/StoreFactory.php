<?php

namespace Database\Factories;

use App\Models\FranchiseAccount;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'franchise_account_id' => FranchiseAccount::factory(),
            'name' => $name,
            'slug' => Store::generateUniqueSlug($name),
            'code' => strtoupper(Str::random(6)),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => 'US',
            'timezone' => 'America/New_York',
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
