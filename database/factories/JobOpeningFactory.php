<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Enums\JobOpeningStatus;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobOpening>
 */
class JobOpeningFactory extends Factory
{
    protected $model = JobOpening::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'hiring_workflow_id' => HiringWorkflow::factory(),
            'title' => $this->faker->jobTitle(),
            'description' => $this->faker->optional()->paragraph(),
            'employment_type' => $this->faker->optional()->randomElement(EmploymentType::cases()),
            'openings_count'  => 1,
            'status'          => JobOpeningStatus::Draft,
            'published_at' => null,
            'closed_at' => null,
            'created_by' => null,
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status'       => JobOpeningStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state([
            'status'       => JobOpeningStatus::Closed,
            'published_at' => now()->subDay(),
            'closed_at'    => now(),
        ]);
    }

    public function forStore(Store $store): static
    {
        return $this->state(['store_id' => $store->id]);
    }

    public function withWorkflow(HiringWorkflow $workflow): static
    {
        return $this->state([
            'store_id' => $workflow->store_id,
            'hiring_workflow_id' => $workflow->id,
        ]);
    }
}
