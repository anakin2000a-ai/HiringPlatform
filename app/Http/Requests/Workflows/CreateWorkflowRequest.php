<?php

namespace App\Http\Requests\Workflows;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:draft'],
            'parent_workflow_id' => ['sometimes', 'nullable', 'integer', 'exists:hiring_workflows,id'],

            'stages' => ['sometimes', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.stage_type' => ['required', 'string', 'in:application,screening,interview,documents,approval,onboarding,hired,rejected,custom'],
            'stages.*.position' => ['required', 'integer', 'min:1'],
            'stages.*.is_initial' => ['sometimes', 'boolean'],
            'stages.*.is_terminal' => ['sometimes', 'boolean'],
            'stages.*.auto_advance_enabled' => ['sometimes', 'boolean'],
            'stages.*.configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $stages = $this->input('stages', []);

            if (empty($stages)) {
                return;
            }

            // Skip cross-field checks if structural validation already failed
            foreach ($v->errors()->keys() as $key) {
                if (str_starts_with($key, 'stages')) {
                    return;
                }
            }

            $this->validateExactlyOneInitialStage($v, $stages);
            $this->validateUniquePositions($v, $stages);
            $this->validateUniqueNames($v, $stages);
        });
    }

    private function validateExactlyOneInitialStage(Validator $v, array $stages): void
    {
        $initialCount = count(array_filter($stages, fn ($s) => ($s['is_initial'] ?? false) == true));

        if ($initialCount === 0) {
            $v->errors()->add('stages', 'Exactly one initial stage is required when providing stages.');
        } elseif ($initialCount > 1) {
            $v->errors()->add('stages', 'Only one initial stage is allowed per workflow.');
        }
    }

    private function validateUniquePositions(Validator $v, array $stages): void
    {
        $positions = array_column($stages, 'position');
        $positions = array_filter($positions, fn ($p) => $p !== null);

        if (count($positions) !== count(array_unique($positions))) {
            $v->errors()->add('stages', 'Stage positions must be unique within a workflow.');
        }
    }

    private function validateUniqueNames(Validator $v, array $stages): void
    {
        $names = array_column($stages, 'name');
        $names = array_filter($names, fn ($n) => $n !== null);
        $normalized = array_map('strtolower', $names);

        if (count($normalized) !== count(array_unique($normalized))) {
            $v->errors()->add('stages', 'Stage names must be unique within a workflow.');
        }
    }
}
