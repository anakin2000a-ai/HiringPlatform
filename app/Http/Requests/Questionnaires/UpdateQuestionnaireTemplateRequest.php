<?php

namespace App\Http\Requests\Questionnaires;

use App\Enums\QuestionnaireStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionnaireTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'   => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::enum(QuestionnaireStatus::class)],
        ];
    }
}
