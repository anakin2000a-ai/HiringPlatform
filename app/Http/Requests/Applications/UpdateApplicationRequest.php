<?php

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,rejected,hired,withdrawn'],
            'score' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
