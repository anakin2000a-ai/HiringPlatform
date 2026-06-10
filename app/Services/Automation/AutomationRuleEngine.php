<?php

namespace App\Services\Automation;

use App\Models\Application;
use App\Models\AutomationRule;
use App\Models\ApplicantDocument;
use App\Models\StageDocumentRequirement;

class AutomationRuleEngine
{
    public const MAX_DEPTH = 5;

    public function __construct(
        private readonly RuleConditionEvaluator $conditionEvaluator,
        private readonly RuleActionExecutor $actionExecutor,
    ) {}

    /**
     * Evaluate all matching automation rules for the given trigger and application.
     *
     * @param  array<int>  $executedRuleIds  IDs already executed in this evaluation cycle (loop guard)
     */
    public function evaluate(
        string $trigger,
        Application $application,
        array $executedRuleIds = [],
        int $depth = 0,
    ): void {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        $application->loadMissing(['jobOpening']);

        $storeId    = $application->jobOpening->store_id;
        $workflowId = $application->jobOpening->hiring_workflow_id;
        $stageId    = $application->current_stage_id;

        $rules = AutomationRule::where('store_id', $storeId)
            ->where('is_active', true)
            ->where('trigger', $trigger)
            ->where(function ($q) use ($workflowId): void {
                $q->whereNull('hiring_workflow_id')
                  ->orWhere('hiring_workflow_id', $workflowId);
            })
            ->where(function ($q) use ($stageId): void {
                $q->whereNull('workflow_stage_id')
                  ->orWhere('workflow_stage_id', $stageId);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $context = $this->buildContext($application);

        foreach ($rules as $rule) {
            if (in_array($rule->id, $executedRuleIds, true)) {
                continue;
            }

            if (! $this->conditionEvaluator->evaluate($rule->conditions, $context)) {
                continue;
            }

            $executedRuleIds[] = $rule->id;

            $this->actionExecutor->executeAll(
                $rule->actions,
                $application,
                $this,
                $executedRuleIds,
                $depth,
            );
        }
    }

    private function buildContext(Application $application): array
    {
        $application->loadMissing([
            'applicant',
            'currentStage',
            'jobOpening',
            'answers.question',
            'applicantDocuments.documentTemplate',
            'applicantDocuments.stageDocumentRequirement',
        ]);

        $answers = [];
        foreach ($application->answers as $answer) {
            $key = $answer->question?->question_key;
            if ($key !== null) {
                $answers[$key] = $answer->answer;
            }
        }

        $documents = [];
        foreach ($application->applicantDocuments as $doc) {
            $docType = $doc->documentTemplate?->document_type;
            if ($docType !== null) {
                $documents[$docType] = ['status' => $doc->status];
            }
        }

        $requiredDocs = $application->applicantDocuments->filter(
            fn ($doc) => $doc->stageDocumentRequirement?->is_required === true
        );

        $allRequiredApproved = $requiredDocs->isNotEmpty()
            && $requiredDocs->every(fn ($doc) => $doc->status === 'approved');

        $documents['all_required'] = [
            'status' => $allRequiredApproved ? 'approved' : 'pending',
        ];

        return [
            'applicant' => [
                'email' => $application->applicant?->email,
                'phone' => $application->applicant?->phone,
            ],
            'application' => [
                'status' => $application->status,
                'score'  => $application->score,
            ],
            'current_stage' => [
                // 'slug' maps to name — no slug column on workflow_stages in current schema
                'slug'       => $application->currentStage?->name,
                'stage_type' => $application->currentStage?->stage_type,
            ],
            'answers'   => $answers,
            'documents' => $documents,
        ];
    }
}
