<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'application_id'             => $this->application_id,
            'questionnaire_template_id'  => $this->questionnaire_template_id,
            'questionnaire_question_id'  => $this->questionnaire_question_id,
            'answer'                     => $this->answer,
            'answered_at'                => $this->answered_at,
            'created_at'                 => $this->created_at,
            'updated_at'                 => $this->updated_at,
        ];
    }
}
