<?php

namespace App\Http\Requests\Automation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hiring_workflow_id'   => ['nullable', 'integer', 'exists:hiring_workflows,id'],
            'workflow_stage_id'    => ['nullable', 'integer', 'exists:workflow_stages,id'],
            'name'                 => ['sometimes', 'string', 'max:255'],
            'trigger'              => ['sometimes', 'string', Rule::in(CreateAutomationRuleRequest::TRIGGERS)],
            'conditions'           => ['nullable', 'array'],
            'conditions.group'     => ['sometimes', 'string', Rule::in(['all', 'any'])],
            'conditions.rules'     => ['sometimes', 'array'],
            'conditions.rules.*'   => ['sometimes', 'array'],
            'actions'              => ['sometimes', 'array', 'min:1'],
            'actions.*'            => ['sometimes', 'array'],
            'actions.*.type'       => ['sometimes', 'string', Rule::in(CreateAutomationRuleRequest::ACTION_TYPES)],
            'priority'             => ['sometimes', 'integer', 'min:0'],
            'is_active'            => ['sometimes', 'boolean'],
        ];
    }
}
