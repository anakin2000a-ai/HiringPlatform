<?php

namespace Database\Factories;

use App\Models\Applicant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    protected $model = Applicant::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'birth_date' => $this->faker->optional()->date(),
            'source' => $this->faker->optional()->randomElement(['career_page', 'referral', 'linkedin', 'indeed']),
            'metadata' => null,
        ];
    }

    public function withPhone(string $phone): static
    {
        return $this->state(['phone' => $phone]);
    }

    public function noEmail(): static
    {
        return $this->state(['email' => null]);
    }
}
