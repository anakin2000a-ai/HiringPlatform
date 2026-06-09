<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionnaireQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'questionnaire_template_id'  => $this->questionnaire_template_id,
            'question_key'               => $this->question_key,
            'label'                      => $this->label,
            'type'                       => $this->type,
            'options'                    => $this->options,
            'validation_rules'           => $this->validation_rules,
            'visibility_rules'           => $this->visibility_rules,
            'is_required'                => $this->is_required,
            'position'                   => $this->position,
            'created_at'                 => $this->created_at,
            'updated_at'                 => $this->updated_at,
        ];
    }
}
