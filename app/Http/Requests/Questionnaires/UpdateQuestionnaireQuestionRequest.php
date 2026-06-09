<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionnaireQuestionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'question_key'     => ['sometimes', 'string', 'max:150'],
            'label'            => ['sometimes', 'string'],
            'type'             => ['sometimes', 'string', 'in:text,number,boolean,date,select,multiselect'],
            'options'          => ['nullable', 'array'],
            'validation_rules' => ['nullable', 'array'],
            'visibility_rules' => ['nullable', 'array'],
            'is_required'      => ['sometimes', 'boolean'],
            'position'         => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
