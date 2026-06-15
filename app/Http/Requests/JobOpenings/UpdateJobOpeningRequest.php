<?php

namespace App\Http\Requests\JobOpenings;

use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'employment_type' => ['sometimes', 'nullable', 'string', Rule::enum(EmploymentType::class)],
            'openings_count' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
