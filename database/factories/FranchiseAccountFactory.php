<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\FranchiseAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FranchiseAccount>
 */
class FranchiseAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => AccountStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => AccountStatus::Inactive]);
    }
}
