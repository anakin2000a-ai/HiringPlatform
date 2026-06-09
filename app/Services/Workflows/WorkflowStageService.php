<?php

namespace App\Services\Workflows;

use App\Models\HiringWorkflow;
use App\Models\WorkflowStage;
use Illuminate\Validation\ValidationException;

class WorkflowStageService
{
    public function create(HiringWorkflow $workflow, array $data): WorkflowStage
    {
        $this->validate($workflow, $data);

        return WorkflowStage::create([
            'hiring_workflow_id' => $workflow->id,
            'name' => $data['name'],
            'stage_type' => $data['stage_type'],
            'position' => $data['position'],
            'is_initial' => $data['is_initial'] ?? false,
            'is_terminal' => $data['is_terminal'] ?? false,
            'auto_advance_enabled' => $data['auto_advance_enabled'] ?? false,
            'configuration' => $data['configuration'] ?? null,
        ]);
    }

    public function update(WorkflowStage $stage, array $data): WorkflowStage
    {
        $this->validate($stage->workflow, $data, $stage->id);

        $stage->update($data);

        return $stage->fresh();
    }

    public function delete(WorkflowStage $stage): void
    {
        $stage->delete();
    }

    private function validate(HiringWorkflow $workflow, array $data, ?int $excludeId = null): void
    {
        if (($data['is_initial'] ?? false) === true) {
            $query = WorkflowStage::where('hiring_workflow_id', $workflow->id)
                ->where('is_initial', true);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'is_initial' => ['This workflow already has an initial stage.'],
                ]);
            }
        }

        if (isset($data['position'])) {
            $query = WorkflowStage::where('hiring_workflow_id', $workflow->id)
                ->where('position', $data['position']);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'position' => ["Position {$data['position']} is already used in this workflow."],
                ]);
            }
        }

        if (isset($data['name'])) {
            $query = WorkflowStage::where('hiring_workflow_id', $workflow->id)
                ->whereRaw('LOWER(name) = LOWER(?)', [$data['name']]);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'name' => ["A stage named '{$data['name']}' already exists in this workflow."],
                ]);
            }
        }
    }
}
