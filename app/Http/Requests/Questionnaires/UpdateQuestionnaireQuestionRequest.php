<?php

namespace App\Http\Requests\Questionnaires;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionnaireQuestionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'question_key'     => ['sometimes', 'string', 'max:150'],
            'label'            => ['sometimes', 'string'],
            'type'             => ['sometimes', 'string', Rule::enum(QuestionType::class)],
            'options'          => ['nullable', 'array'],
            'validation_rules' => ['nullable', 'array'],
            'visibility_rules' => ['nullable', 'array'],
            'is_required'      => ['sometimes', 'boolean'],
            'position'         => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
