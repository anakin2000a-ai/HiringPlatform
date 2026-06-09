<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

class CreateQuestionnaireTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:255'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'status'  => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }
}
