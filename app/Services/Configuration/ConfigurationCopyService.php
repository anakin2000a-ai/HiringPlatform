<?php

namespace App\Services\Configuration;

use App\Models\AutomationRule;
use App\Models\ConfigurationCopyLog;
use App\Models\DocumentTemplate;
use App\Models\HiringWorkflow;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\StageQuestionnaireAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Support\Facades\DB;

class ConfigurationCopyService
{
    public function copy(
        Store $sourceStore,
        Store $targetStore,
        User  $copiedBy,
        bool  $copyWorkflows       = true,
        bool  $copyQuestionnaires  = true,
        bool  $copyDocuments       = true,
        bool  $copyAutomationRules = true,
    ): ConfigurationCopyLog {
        return DB::transaction(function () use (
            $sourceStore, $targetStore, $copiedBy,
            $copyWorkflows, $copyQuestionnaires, $copyDocuments, $copyAutomationRules
        ): ConfigurationCopyLog {
            $counts = [
                'workflows'                      => 0,
                'workflow_stages'                => 0,
                'workflow_stage_transitions'     => 0,
                'questionnaire_templates'        => 0,
                'questionnaire_questions'        => 0,
                'stage_questionnaire_assignments' => 0,
                'document_templates'             => 0,
                'stage_document_requirements'    => 0,
                'automation_rules'               => 0,
            ];

            $metadata = [
                'options' => [
                    'copy_workflows'        => $copyWorkflows,
                    'copy_questionnaires'   => $copyQuestionnaires,
                    'copy_documents'        => $copyDocuments,
                    'copy_automation_rules' => $copyAutomationRules,
                ],
                'skipped' => [],
                'renames' => [],
            ];

            $stageIdMap    = []; // source stage id  => target stage id
            $workflowIdMap = []; // source workflow id => target workflow id

            // ------------------------------------------------------------------
            // 1. Workflows, Stages, Transitions
            // ------------------------------------------------------------------
            if ($copyWorkflows) {
                $sourceWorkflows = HiringWorkflow::where('store_id', $sourceStore->id)->get();

                foreach ($sourceWorkflows as $srcWorkflow) {
                    $name = $this->resolveWorkflowName(
                        $srcWorkflow->name,
                        $srcWorkflow->version,
                        $targetStore,
                        $sourceStore,
                        $metadata
                    );

                    $newWorkflow = HiringWorkflow::create([
                        'store_id'           => $targetStore->id,
                        'name'               => $name,
                        'version'            => $srcWorkflow->version,
                        'status'             => $srcWorkflow->status,
                        'parent_workflow_id' => null,
                        'created_by'         => $copiedBy->id,
                        'published_at'       => $srcWorkflow->published_at,
                        'archived_at'        => $srcWorkflow->archived_at,
                    ]);

                    $workflowIdMap[$srcWorkflow->id] = $newWorkflow->id;
                    $counts['workflows']++;

                    $stages = WorkflowStage::where('hiring_workflow_id', $srcWorkflow->id)
                        ->orderBy('position')
                        ->get();

                    foreach ($stages as $srcStage) {
                        $newStage = WorkflowStage::create([
                            'hiring_workflow_id'   => $newWorkflow->id,
                            'name'                 => $srcStage->name,
                            'stage_type'           => $srcStage->stage_type,
                            'position'             => $srcStage->position,
                            'is_initial'           => $srcStage->is_initial,
                            'is_terminal'          => $srcStage->is_terminal,
                            'auto_advance_enabled' => $srcStage->auto_advance_enabled,
                            'configuration'        => $srcStage->configuration,
                        ]);

                        $stageIdMap[$srcStage->id] = $newStage->id;
                        $counts['workflow_stages']++;
                    }

                    $transitions = WorkflowStageTransition::where('hiring_workflow_id', $srcWorkflow->id)->get();

                    foreach ($transitions as $srcTransition) {
                        $newFromId = $stageIdMap[$srcTransition->from_stage_id] ?? null;
                        $newToId   = $stageIdMap[$srcTransition->to_stage_id] ?? null;

                        if ($newFromId === null || $newToId === null) {
                            continue;
                        }

                        WorkflowStageTransition::create([
                            'hiring_workflow_id'   => $newWorkflow->id,
                            'from_stage_id'        => $newFromId,
                            'to_stage_id'          => $newToId,
                            'name'                 => $srcTransition->name,
                            'is_manual_allowed'    => $srcTransition->is_manual_allowed,
                            'is_automatic_allowed' => $srcTransition->is_automatic_allowed,
                            'conditions'           => $srcTransition->conditions,
                        ]);

                        $counts['workflow_stage_transitions']++;
                    }
                }
            }

            // ------------------------------------------------------------------
            // 2. Questionnaire Templates, Questions, Stage Assignments
            // ------------------------------------------------------------------
            $questionnaireTemplateIdMap = [];

            if ($copyQuestionnaires) {
                $sourceTemplates = QuestionnaireTemplate::where('store_id', $sourceStore->id)->get();

                foreach ($sourceTemplates as $srcTemplate) {
                    $name = $this->resolveQuestionnaireName(
                        $srcTemplate->name,
                        $srcTemplate->version,
                        $targetStore,
                        $sourceStore,
                        $metadata
                    );

                    $newTemplate = QuestionnaireTemplate::create([
                        'store_id'   => $targetStore->id,
                        'name'       => $name,
                        'version'    => $srcTemplate->version,
                        'status'     => $srcTemplate->status,
                        'created_by' => $copiedBy->id,
                    ]);

                    $questionnaireTemplateIdMap[$srcTemplate->id] = $newTemplate->id;
                    $counts['questionnaire_templates']++;

                    foreach ($srcTemplate->questions()->orderBy('position')->get() as $srcQuestion) {
                        QuestionnaireQuestion::create([
                            'questionnaire_template_id' => $newTemplate->id,
                            'question_key'              => $srcQuestion->question_key,
                            'label'                     => $srcQuestion->label,
                            'type'                      => $srcQuestion->type,
                            'options'                   => $srcQuestion->options,
                            'validation_rules'          => $srcQuestion->validation_rules,
                            'visibility_rules'          => $srcQuestion->visibility_rules,
                            'is_required'               => $srcQuestion->is_required,
                            'position'                  => $srcQuestion->position,
                        ]);
                        $counts['questionnaire_questions']++;
                    }
                }

                if ($copyWorkflows && ! empty($stageIdMap)) {
                    $assignments = StageQuestionnaireAssignment::whereIn(
                        'workflow_stage_id',
                        array_keys($stageIdMap)
                    )->get();

                    $skipped = 0;
                    foreach ($assignments as $srcAssignment) {
                        $newStageId    = $stageIdMap[$srcAssignment->workflow_stage_id] ?? null;
                        $newTemplateId = $questionnaireTemplateIdMap[$srcAssignment->questionnaire_template_id] ?? null;

                        if ($newStageId === null || $newTemplateId === null) {
                            $skipped++;
                            continue;
                        }

                        StageQuestionnaireAssignment::create([
                            'workflow_stage_id'         => $newStageId,
                            'questionnaire_template_id' => $newTemplateId,
                            'is_required'               => $srcAssignment->is_required,
                        ]);
                        $counts['stage_questionnaire_assignments']++;
                    }

                    if ($skipped > 0) {
                        $metadata['skipped']['stage_questionnaire_assignments'] = $skipped;
                    }
                } else {
                    // copy_questionnaires=true but copy_workflows=false: count skipped assignments
                    $skipped = StageQuestionnaireAssignment::whereHas(
                        'workflowStage',
                        fn ($q) => $q->whereHas('workflow', fn ($q2) => $q2->where('store_id', $sourceStore->id))
                    )->count();

                    if ($skipped > 0) {
                        $metadata['skipped']['stage_questionnaire_assignments'] = $skipped;
                    }
                }
            }

            // ------------------------------------------------------------------
            // 3. Document Templates, Stage Requirements
            // ------------------------------------------------------------------
            $documentTemplateIdMap = [];

            if ($copyDocuments) {
                $sourceDocTemplates = DocumentTemplate::where('store_id', $sourceStore->id)->get();

                foreach ($sourceDocTemplates as $srcDocTemplate) {
                    $newDocTemplate = DocumentTemplate::create([
                        'store_id'           => $targetStore->id,
                        'name'               => $srcDocTemplate->name,
                        'document_type'      => $srcDocTemplate->document_type,
                        'requires_signature' => $srcDocTemplate->requires_signature,
                        'description'        => $srcDocTemplate->description,
                        'created_by'         => $copiedBy->id,
                    ]);

                    $documentTemplateIdMap[$srcDocTemplate->id] = $newDocTemplate->id;
                    $counts['document_templates']++;
                }

                if ($copyWorkflows && ! empty($stageIdMap)) {
                    $requirements = StageDocumentRequirement::whereIn(
                        'workflow_stage_id',
                        array_keys($stageIdMap)
                    )->get();

                    $skipped = 0;
                    foreach ($requirements as $srcReq) {
                        $newStageId       = $stageIdMap[$srcReq->workflow_stage_id] ?? null;
                        $newDocTemplateId = $documentTemplateIdMap[$srcReq->document_template_id] ?? null;

                        if ($newStageId === null || $newDocTemplateId === null) {
                            $skipped++;
                            continue;
                        }

                        StageDocumentRequirement::create([
                            'workflow_stage_id'          => $newStageId,
                            'document_template_id'       => $newDocTemplateId,
                            'is_required'                => $srcReq->is_required,
                            'due_days_after_stage_entry' => $srcReq->due_days_after_stage_entry,
                        ]);
                        $counts['stage_document_requirements']++;
                    }

                    if ($skipped > 0) {
                        $metadata['skipped']['stage_document_requirements'] = $skipped;
                    }
                } else {
                    // copy_documents=true but copy_workflows=false: count skipped requirements
                    $skipped = StageDocumentRequirement::whereHas(
                        'workflowStage',
                        fn ($q) => $q->whereHas('workflow', fn ($q2) => $q2->where('store_id', $sourceStore->id))
                    )->count();

                    if ($skipped > 0) {
                        $metadata['skipped']['stage_document_requirements'] = $skipped;
                    }
                }
            }

            // ------------------------------------------------------------------
            // 4. Automation Rules
            // ------------------------------------------------------------------
            if ($copyAutomationRules) {
                $query = AutomationRule::where('store_id', $sourceStore->id);

                if (! $copyWorkflows) {
                    // Only store-level rules (no workflow/stage scope)
                    $skippedRules = (clone $query)
                        ->where(fn ($q) => $q->whereNotNull('hiring_workflow_id')->orWhereNotNull('workflow_stage_id'))
                        ->count();

                    if ($skippedRules > 0) {
                        $metadata['skipped']['automation_rules'] = $skippedRules;
                    }

                    $query->whereNull('hiring_workflow_id')->whereNull('workflow_stage_id');
                }

                foreach ($query->get() as $srcRule) {
                    AutomationRule::create([
                        'store_id'           => $targetStore->id,
                        'hiring_workflow_id' => isset($workflowIdMap[$srcRule->hiring_workflow_id])
                            ? $workflowIdMap[$srcRule->hiring_workflow_id]
                            : null,
                        'workflow_stage_id'  => isset($stageIdMap[$srcRule->workflow_stage_id])
                            ? $stageIdMap[$srcRule->workflow_stage_id]
                            : null,
                        'name'               => $srcRule->name,
                        'trigger'            => $srcRule->trigger,
                        'conditions'         => $srcRule->conditions,
                        'actions'            => $srcRule->actions,
                        'priority'           => $srcRule->priority,
                        'is_active'          => $srcRule->is_active,
                        'created_by'         => $copiedBy->id,
                    ]);

                    $counts['automation_rules']++;
                }
            }

            // ------------------------------------------------------------------
            // 5. Write log
            // ------------------------------------------------------------------
            return ConfigurationCopyLog::create([
                'source_store_id' => $sourceStore->id,
                'target_store_id' => $targetStore->id,
                'copied_by'       => $copiedBy->id,
                'copied_items'    => $counts,
                'metadata'        => $metadata,
                'created_at'      => now(),
            ]);
        });
    }

    // --------------------------------------------------------------------------
    // Name conflict resolution
    // --------------------------------------------------------------------------

    private function resolveWorkflowName(
        string $name,
        int    $version,
        Store  $targetStore,
        Store  $sourceStore,
        array  &$metadata
    ): string {
        if (! $this->workflowNameExists($name, $version, $targetStore)) {
            return $name;
        }

        $candidate = "{$name} (Copy from {$sourceStore->store_name})";

        if (! $this->workflowNameExists($candidate, $version, $targetStore)) {
            $metadata['renames'][] = ['type' => 'workflow', 'original' => $name, 'renamed_to' => $candidate];
            return $candidate;
        }

        $i = 2;
        do {
            $numbered = "{$candidate} {$i}";
            $i++;
        } while ($this->workflowNameExists($numbered, $version, $targetStore) && $i < 1000);

        $metadata['renames'][] = ['type' => 'workflow', 'original' => $name, 'renamed_to' => $numbered];
        return $numbered;
    }

    private function workflowNameExists(string $name, int $version, Store $store): bool
    {
        return HiringWorkflow::where('store_id', $store->id)
            ->where('name', $name)
            ->where('version', $version)
            ->exists();
    }

    private function resolveQuestionnaireName(
        string $name,
        int    $version,
        Store  $targetStore,
        Store  $sourceStore,
        array  &$metadata
    ): string {
        if (! $this->questionnaireNameExists($name, $version, $targetStore)) {
            return $name;
        }

        $candidate = "{$name} (Copy from {$sourceStore->store_name})";

        if (! $this->questionnaireNameExists($candidate, $version, $targetStore)) {
            $metadata['renames'][] = ['type' => 'questionnaire', 'original' => $name, 'renamed_to' => $candidate];
            return $candidate;
        }

        $i = 2;
        do {
            $numbered = "{$candidate} {$i}";
            $i++;
        } while ($this->questionnaireNameExists($numbered, $version, $targetStore) && $i < 1000);

        $metadata['renames'][] = ['type' => 'questionnaire', 'original' => $name, 'renamed_to' => $numbered];
        return $numbered;
    }

    private function questionnaireNameExists(string $name, int $version, Store $store): bool
    {
        return QuestionnaireTemplate::where('store_id', $store->id)
            ->where('name', $name)
            ->where('version', $version)
            ->exists();
    }
}
