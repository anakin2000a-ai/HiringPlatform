<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HiringWorkflow>
 */
class HiringWorkflowFactory extends Factory
{
    protected $model = HiringWorkflow::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => $this->faker->unique()->words(3, true) . ' Workflow',
            'version' => 1,
            'status' => WorkflowStatus::Draft,
            'parent_workflow_id' => null,
            'created_by' => null,
            'published_at' => null,
            'archived_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status'       => WorkflowStatus::Active,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state([
            'status'       => WorkflowStatus::Archived,
            'published_at' => now()->subDay(),
            'archived_at'  => now(),
        ]);
    }

    public function forStore(Store $store): static
    {
        return $this->state(['store_id' => $store->id]);
    }
}
