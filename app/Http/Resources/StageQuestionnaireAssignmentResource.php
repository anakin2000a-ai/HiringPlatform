<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageQuestionnaireAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'workflow_stage_id'          => $this->workflow_stage_id,
            'questionnaire_template_id'  => $this->questionnaire_template_id,
            'is_required'                => $this->is_required,
            'questionnaire'              => new QuestionnaireTemplateResource($this->whenLoaded('questionnaireTemplate')),
            'created_at'                 => $this->created_at,
            'updated_at'                 => $this->updated_at,
        ];
    }
}
