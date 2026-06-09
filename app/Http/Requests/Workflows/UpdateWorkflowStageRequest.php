<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

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
            'stage_type' => ['sometimes', 'string', 'in:application,screening,interview,documents,approval,onboarding,hired,rejected,custom'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'is_initial' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'auto_advance_enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
