<?php

namespace App\Http\Requests\JobOpenings;

use App\Enums\EmploymentType;
use App\Enums\JobOpeningStatus;
use App\Models\HiringWorkflow;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hiring_workflow_id' => ['required', 'integer', 'exists:hiring_workflows,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'employment_type' => ['sometimes', 'nullable', 'string', Rule::enum(EmploymentType::class)],
            'openings_count' => ['sometimes', 'integer', 'min:1'],
        ];
    }

   public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v): void {
        $workflowId = $this->input('hiring_workflow_id');

        if (! $workflowId || $v->errors()->has('hiring_workflow_id')) {
            return;
        }

        $store = $this->route('store');

        $belongs = HiringWorkflow::where('id', $workflowId)
            ->where('store_id', $store->id)
            ->exists();

        if (! $belongs) {
            $v->errors()->add(
                'hiring_workflow_id',
                'The selected workflow does not belong to this store.'
            );

            return;
        }

        $exists = \App\Models\JobOpening::where('store_id', $store->id)
            ->where('title', $this->input('title'))
            ->where('status', JobOpeningStatus::Published)
            ->exists();

        if ($exists) {
            $v->errors()->add(
                'title',
                'A published job opening with this title already exists for this store.'
            );
        }
    });
}
}
