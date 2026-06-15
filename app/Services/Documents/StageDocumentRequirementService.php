<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\WorkflowStage;
use Illuminate\Validation\ValidationException;

class StageDocumentRequirementService
{
    
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
