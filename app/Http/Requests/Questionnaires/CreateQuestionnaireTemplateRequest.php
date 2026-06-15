<?php

namespace App\Http\Requests\Questionnaires;

use App\Enums\QuestionnaireStatus;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQuestionnaireTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'name'                       => ['required', 'string', 'max:255'],
            'version'                    => ['sometimes', 'integer', 'min:1'],
            'status'                     => ['sometimes', 'string', Rule::enum(QuestionnaireStatus::class)],
            'questions'                  => ['sometimes', 'array'],
            'questions.*.question_key'   => ['required', 'string', 'max:100'],
            'questions.*.label'          => ['required', 'string', 'max:500'],
            'questions.*.type'           => ['required', 'string', 'in:text,boolean,number,multiple_choice,single_choice'],
            'questions.*.is_required'    => ['sometimes', 'boolean'],
            'questions.*.position'       => ['required', 'integer', 'min:1'],

            'stage_assignment'                       => ['sometimes', 'nullable', 'array'],
            'stage_assignment.workflow_stage_id'     => ['required_with:stage_assignment', 'integer', 'exists:workflow_stages,id'],
            'stage_assignment.is_required'           => ['sometimes', 'boolean'],
        ];

        return $rules;
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $stageAssignment = $this->input('stage_assignment');
            if (empty($stageAssignment) || empty($stageAssignment['workflow_stage_id'])) {
                return;
            }

            $store = $this->route('store');
            $stageId = (int) $stageAssignment['workflow_stage_id'];

            $stageBelongsToStore = WorkflowStage::where('id', $stageId)
                ->whereHas('workflow', fn ($q) => $q->where('store_id', $store->id))
                ->exists();

            if (! $stageBelongsToStore) {
                $validator->errors()->add(
                    'stage_assignment.workflow_stage_id',
                    'The workflow stage does not belong to this store.'
                );
            }
        });
    }
}
