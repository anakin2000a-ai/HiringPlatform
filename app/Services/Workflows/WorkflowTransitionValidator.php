<?php

namespace App\Services\Workflows;

use App\Models\HiringWorkflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Validation\ValidationException;

class WorkflowTransitionValidator
{
    /**
     * Validate that a transition can be created or updated for the given workflow.
     *
     * @param  int|null  $fromStageId  NULL means initial entry transition (no prior stage)
     */
    public function validate(
        HiringWorkflow $workflow,
        ?int $fromStageId,
        int $toStageId,
        ?int $excludeTransitionId = null
    ): void {
        if ($fromStageId !== null) {
            $this->ensureStageBelongsToWorkflow($workflow, $fromStageId, 'from_stage_id');
        }

        $this->ensureStageBelongsToWorkflow($workflow, $toStageId, 'to_stage_id');

        $this->ensureUnique($workflow, $fromStageId, $toStageId, $excludeTransitionId);
    }

    private function ensureStageBelongsToWorkflow(HiringWorkflow $workflow, int $stageId, string $field): void
    {
        $exists = WorkflowStage::where('id', $stageId)
            ->where('hiring_workflow_id', $workflow->id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                $field => ["Stage {$stageId} does not belong to workflow {$workflow->id}."],
            ]);
        }
    }

    private function ensureUnique(
        HiringWorkflow $workflow,
        ?int $fromStageId,
        int $toStageId,
        ?int $excludeId
    ): void {
        $query = WorkflowStageTransition::where('hiring_workflow_id', $workflow->id)
            ->where('to_stage_id', $toStageId);

        if ($fromStageId === null) {
            $query->whereNull('from_stage_id');
        } else {
            $query->where('from_stage_id', $fromStageId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'from_stage_id' => ['A transition with this from/to stage combination already exists in this workflow.'],
            ]);
        }
    }
}
