<?php

namespace App\Http\Requests\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAutomationRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hiring_workflow_id'   => ['nullable', 'integer', 'exists:hiring_workflows,id'],
            'workflow_stage_id'    => ['nullable', 'integer', 'exists:workflow_stages,id'],
            'name'                 => ['required', 'string', 'max:255', Rule::unique('automation_rules', 'name')],
            'trigger'              => ['required', 'string', Rule::enum(AutomationTrigger::class)],
            'conditions'           => ['nullable', 'array'],
            'conditions.group'     => ['sometimes', 'string', Rule::in(['all', 'any'])],
            'conditions.rules'     => ['sometimes', 'array'],
            'conditions.rules.*'   => ['sometimes', 'array'],
            'actions'              => ['required', 'array', 'min:1'],
            'actions.*'            => ['required', 'array'],
            'actions.*.type'       => ['required', 'string', Rule::enum(AutomationActionType::class)],
            'priority'             => ['sometimes', 'integer', 'min:0'],
            'is_active'            => ['sometimes', 'boolean'],
        ];
    }
}
