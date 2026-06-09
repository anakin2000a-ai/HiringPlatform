<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

class AssignQuestionnaireToStageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'questionnaire_template_id' => ['required', 'integer', 'exists:questionnaire_templates,id'],
            'is_required'               => ['sometimes', 'boolean'],
        ];
    }
}
