<?php

namespace Database\Factories;

use App\Models\HiringWorkflow;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStage>
 */
class WorkflowStageFactory extends Factory
{
    protected $model = WorkflowStage::class;

    private static int $positionSequence = 1;

    public function definition(): array
    {
        return [
            'hiring_workflow_id' => HiringWorkflow::factory(),
            'name' => $this->faker->unique()->word() . ' Stage',
            'stage_type' => $this->faker->randomElement(['application', 'screening', 'interview', 'documents', 'approval', 'onboarding', 'hired', 'rejected', 'custom']),
            'position' => $this->faker->unique()->numberBetween(1, 100),
            'is_initial' => false,
            'is_terminal' => false,
            'auto_advance_enabled' => false,
            'configuration' => null,
        ];
    }

    public function initial(): static
    {
        return $this->state([
            'is_initial' => true,
            'stage_type' => 'application',
            'position' => 1,
        ]);
    }

    public function terminal(): static
    {
        return $this->state([
            'is_terminal' => true,
            'stage_type' => 'hired',
        ]);
    }

    public function forWorkflow(HiringWorkflow $workflow): static
    {
        return $this->state(['hiring_workflow_id' => $workflow->id]);
    }
}
