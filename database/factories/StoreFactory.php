<?php

namespace Database\Factories;

use App\Models\FranchiseAccount;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'franchise_account_id' => FranchiseAccount::factory(),
            'store_name' => fake()->company(),
        ];
    }
}
