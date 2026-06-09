<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

class CreateQuestionnaireQuestionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'question_key'     => ['required', 'string', 'max:150'],
            'label'            => ['required', 'string'],
            'type'             => ['required', 'string', 'in:text,number,boolean,date,select,multiselect'],
            'options'          => ['nullable', 'array'],
            'validation_rules' => ['nullable', 'array'],
            'visibility_rules' => ['nullable', 'array'],
            'is_required'      => ['sometimes', 'boolean'],
            'position'         => ['required', 'integer', 'min:1'],
        ];
    }
}
