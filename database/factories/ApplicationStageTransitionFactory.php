<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationStageTransition;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationStageTransition>
 */
class ApplicationStageTransitionFactory extends Factory
{
    protected $model = ApplicationStageTransition::class;

    public function definition(): array
    {
        return [
            'application_id'  => Application::factory(),
            'from_stage_id'   => null,
            'to_stage_id'     => WorkflowStage::factory(),
            'changed_by'      => null,
            'transition_type' => 'manual',
            'reason'          => null,
            'metadata'        => null,
            'created_at'      => now(),
        ];
    }

    public function withReason(string $reason): static
    {
        return $this->state(['reason' => $reason]);
    }

    public function automatic(): static
    {
        return $this->state(['transition_type' => 'automatic']);
    }
}
