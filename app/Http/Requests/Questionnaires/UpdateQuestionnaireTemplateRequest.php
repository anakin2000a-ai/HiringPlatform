<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionnaireTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'   => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }
}
