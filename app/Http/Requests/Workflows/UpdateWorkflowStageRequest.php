<?php

namespace App\Http\Requests\Workflows;

use App\Enums\StageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkflowStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'stage_type' => ['sometimes', 'string', Rule::enum(StageType::class)],
            'position' => ['sometimes', 'integer', 'min:1'],
            'is_initial' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'auto_advance_enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
