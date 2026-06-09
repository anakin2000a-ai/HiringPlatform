<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

class SubmitApplicantAnswersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'questionnaire_template_id'             => ['required', 'integer', 'exists:questionnaire_templates,id'],
            'answers'                               => ['required', 'array', 'min:1'],
            'answers.*.questionnaire_question_id'   => ['required', 'integer', 'exists:questionnaire_questions,id'],
            'answers.*.answer'                      => ['present', 'nullable'],
        ];
    }
}
