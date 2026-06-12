<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class CreateDocumentTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        $storeId = $this->route('store')?->id;

        return [
            'name'                                       => ['required', 'string', 'max:255'],
            'document_type'                              => ['required', 'string', 'max:100'],
            'requires_signature'                         => ['sometimes', 'boolean'],
            'description'                                => ['nullable', 'string'],
            'requirements'                               => ['sometimes', 'array'],
            'requirements.*.workflow_stage_id'           => [
                'required',
                'integer',
                'exists:workflow_stages,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($storeId) {
                    $stage = \App\Models\WorkflowStage::find($value);
                    if ($stage && $stage->workflow->store_id !== $storeId) {
                        $fail('The selected stage does not belong to this store.');
                    }
                },
            ],
            'requirements.*.is_required'                 => ['sometimes', 'boolean'],
            'requirements.*.due_days_after_stage_entry'  => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $requirements = $this->input('requirements', []);
                if (! is_array($requirements)) {
                    return;
                }

                $stageIds = array_filter(array_column($requirements, 'workflow_stage_id'));
                if (count($stageIds) !== count(array_unique($stageIds))) {
                    $validator->errors()->add('requirements', 'Each workflow stage may only appear once in requirements.');
                }
            },
        ];
    }
}
