<?php

namespace App\Http\Requests\Automation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAutomationRuleRequest extends FormRequest
{
    public const TRIGGERS = [
        'application_created',
        'stage_entered',
        'answer_submitted',
        'document_submitted',
        'document_signed',
        'document_approved',
        'document_rejected',
    ];

    public const ACTION_TYPES = [
        'move_to_stage',
        'reject_application',
        'mark_hired',
        'create_activity',
        'publish_event',
        'set_score',
        'increment_score',
    ];

    public function rules(): array
    {
        return [
            'hiring_workflow_id'   => ['nullable', 'integer', 'exists:hiring_workflows,id'],
            'workflow_stage_id'    => ['nullable', 'integer', 'exists:workflow_stages,id'],
            'name'                 => ['required', 'string', 'max:255'],
            'trigger'              => ['required', 'string', Rule::in(self::TRIGGERS)],
            'conditions'           => ['nullable', 'array'],
            'conditions.group'     => ['sometimes', 'string', Rule::in(['all', 'any'])],
            'conditions.rules'     => ['sometimes', 'array'],
            'conditions.rules.*'   => ['sometimes', 'array'],
            'actions'              => ['required', 'array', 'min:1'],
            'actions.*'            => ['required', 'array'],
            'actions.*.type'       => ['required', 'string', Rule::in(self::ACTION_TYPES)],
            'priority'             => ['sometimes', 'integer', 'min:0'],
            'is_active'            => ['sometimes', 'boolean'],
        ];
    }
}
