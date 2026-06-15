<?php

namespace App\Http\Requests\Questionnaires;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQuestionnaireQuestionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'question_key'     => ['required', 'string', 'max:150'],
            'label'            => ['required', 'string'],
            'type'             => ['required', 'string', Rule::enum(QuestionType::class)],
            'options'          => ['nullable', 'array'],
            'validation_rules' => ['nullable', 'array'],
            'visibility_rules' => ['nullable', 'array'],
            'is_required'      => ['sometimes', 'boolean'],
            'position'         => ['required', 'integer', 'min:1'],
        ];
    }
}
