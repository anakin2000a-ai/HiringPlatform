<?php

namespace App\Services\Questionnaires;

use App\Models\QuestionnaireTemplate;
use App\Models\StageQuestionnaireAssignment;
use App\Models\Store;
use App\Models\WorkflowStage;
use Illuminate\Validation\ValidationException;

class StageQuestionnaireAssignmentService
{
    public function assign(Store $store, WorkflowStage $stage, array $data): StageQuestionnaireAssignment
    {
        $questionnaireId = $data['questionnaire_template_id'];

        $questionnaire = QuestionnaireTemplate::where('id', $questionnaireId)
            ->where('store_id', $store->id)
            ->first();

        if ($questionnaire === null) {
            throw ValidationException::withMessages([
                'questionnaire_template_id' => ['The questionnaire template does not belong to this store.'],
            ]);
        }

        $alreadyAssigned = StageQuestionnaireAssignment::where('workflow_stage_id', $stage->id)
            ->where('questionnaire_template_id', $questionnaireId)
            ->exists();

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'questionnaire_template_id' => ['This questionnaire is already assigned to this stage.'],
            ]);
        }

        return StageQuestionnaireAssignment::create([
            'workflow_stage_id'          => $stage->id,
            'questionnaire_template_id'  => $questionnaireId,
            'is_required'                => $data['is_required'] ?? true,
        ]);
    }
}
