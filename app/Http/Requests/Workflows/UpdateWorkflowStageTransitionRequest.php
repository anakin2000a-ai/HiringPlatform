<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowStageTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_stage_id' => ['sometimes', 'nullable', 'integer', 'exists:workflow_stages,id'],
            'to_stage_id' => ['sometimes', 'integer', 'exists:workflow_stages,id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_manual_allowed' => ['sometimes', 'boolean'],
            'is_automatic_allowed' => ['sometimes', 'boolean'],
            'conditions' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
