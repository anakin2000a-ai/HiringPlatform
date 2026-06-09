<?php

namespace App\Http\Requests\JobOpenings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'openings_count' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
