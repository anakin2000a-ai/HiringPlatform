<?php

namespace App\Http\Requests\Workflows;

use App\Enums\StageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkflowStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'stage_type' => ['required', 'string', Rule::enum(StageType::class)],
            'position' => ['required', 'integer', 'min:1'],
            'is_initial' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'auto_advance_enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
