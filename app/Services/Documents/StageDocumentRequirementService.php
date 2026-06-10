<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\WorkflowStage;
use Illuminate\Validation\ValidationException;

class StageDocumentRequirementService
{
    public function create(WorkflowStage $stage, array $data): StageDocumentRequirement
    {
        $template = DocumentTemplate::findOrFail($data['document_template_id']);

        $storeId = $stage->workflow->store_id;
        if ($template->store_id !== $storeId) {
            throw ValidationException::withMessages([
                'document_template_id' => ['The document template does not belong to this store.'],
            ]);
        }

        $duplicate = StageDocumentRequirement::where('workflow_stage_id', $stage->id)
            ->where('document_template_id', $template->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'document_template_id' => ['This document template is already required for this stage.'],
            ]);
        }

        return StageDocumentRequirement::create([
            'workflow_stage_id'          => $stage->id,
            'document_template_id'       => $template->id,
            'is_required'                => $data['is_required'] ?? true,
            'due_days_after_stage_entry' => $data['due_days_after_stage_entry'] ?? null,
        ]);
    }

    public function update(StageDocumentRequirement $requirement, array $data): StageDocumentRequirement
    {
        $allowed = collect($data)->only(['is_required', 'due_days_after_stage_entry'])->all();
        $requirement->update($allowed);

        return $requirement->fresh();
    }

    public function delete(StageDocumentRequirement $requirement): void
    {
        $requirement->delete();
    }
}
