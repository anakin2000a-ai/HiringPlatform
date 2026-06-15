<?php

namespace App\Http\Requests\Questionnaires;

use App\Enums\QuestionnaireStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQuestionnaireTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'                       => ['required', 'string', 'max:255'],
            'version'                    => ['sometimes', 'integer', 'min:1'],
            'status'                     => ['sometimes', 'string', Rule::enum(QuestionnaireStatus::class)],
            'questions'                  => ['sometimes', 'array'],
            'questions.*.question_key'   => ['required', 'string', 'max:100'],
            'questions.*.label'          => ['required', 'string', 'max:500'],
            'questions.*.type'           => ['required', 'string', 'in:text,boolean,number,multiple_choice,single_choice'],
            'questions.*.is_required'    => ['sometimes', 'boolean'],
            'questions.*.position'       => ['required', 'integer', 'min:1'],
        ];
    }
}
