<?php

namespace Database\Factories;

use App\Models\HiringWorkflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStageTransition>
 */
class WorkflowStageTransitionFactory extends Factory
{
    protected $model = WorkflowStageTransition::class;

    public function definition(): array
    {
        return [
            'hiring_workflow_id' => HiringWorkflow::factory(),
            'from_stage_id' => null,
            'to_stage_id' => WorkflowStage::factory(),
            'name' => null,
            'is_manual_allowed' => true,
            'is_automatic_allowed' => true,
            'conditions' => null,
        ];
    }

    public function withFromStage(WorkflowStage $from): static
    {
        return $this->state(['from_stage_id' => $from->id]);
    }

    public function withToStage(WorkflowStage $to): static
    {
        return $this->state(['to_stage_id' => $to->id]);
    }
}
