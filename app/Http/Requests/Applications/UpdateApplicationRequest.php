<?php

namespace App\Http\Requests\Applications;

use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(ApplicationStatus::class)],
            'score' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
