<?php

namespace App\Services\Automation;

use App\Models\Application;
use App\Models\OutboxEvent;
use App\Services\Applications\ApplicationStageService;
use App\Services\Applications\WorkflowActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RuleActionExecutor
{
    public function __construct(
        private readonly ApplicationStageService $stageService,
        private readonly WorkflowActivityService $activityService,
    ) {}

    /**
     * Execute all actions for a matched rule.
     * $engine is passed as a parameter to avoid circular constructor injection.
     */
    public function executeAll(
        array $actions,
        Application $application,
        AutomationRuleEngine $engine,
        array &$executedRuleIds,
        int $depth,
    ): void {
        foreach ($actions as $action) {
            $this->executeOne($action, $application, $engine, $executedRuleIds, $depth);
        }
    }

    private function executeOne(
        array $action,
        Application $application,
        AutomationRuleEngine $engine,
        array &$executedRuleIds,
        int $depth,
    ): void {
        $type = $action['type'] ?? '';

        match ($type) {
            'move_to_stage'      => $this->executeMoveToStage($action, $application, $engine, $executedRuleIds, $depth),
            'reject_application' => $this->executeRejectApplication($action, $application, $engine, $executedRuleIds, $depth),
            'mark_hired'         => $this->executeMarkHired($action, $application, $engine, $executedRuleIds, $depth),
            'create_activity'    => $this->executeCreateActivity($action, $application),
            'publish_event'      => $this->executePublishEvent($action, $application),
            'set_score'          => $this->executeSetScore($action, $application),
            'increment_score'    => $this->executeIncrementScore($action, $application),
            default              => null,
        };
    }

    private function executeMoveToStage(
        array $action,
        Application $application,
        AutomationRuleEngine $engine,
        array &$executedRuleIds,
        int $depth,
    ): void {
        // The action payload key is 'stage_slug' for automation rule API compatibility.
        // The current schema has no slug column on workflow_stages, so stage_slug maps
        // to workflow_stages.name until a slug column is introduced.
        $stageIdentifier = $action['stage_slug'] ?? null;
        if ($stageIdentifier === null) {
            return;
        }

        $application->loadMissing(['jobOpening.hiringWorkflow.stages']);
        $stages      = $application->jobOpening->hiringWorkflow->stages;
        $targetStage = $stages->firstWhere('name', $stageIdentifier);

        if ($targetStage === null) {
            $this->logFailedAction($application, $action, "Stage '{$stageIdentifier}' not found in workflow.");
            return;
        }

        try {
            $updated = $this->stageService->move(
                $application,
                $targetStage->id,
                $action['reason'] ?? null,
                null,
                'automatic',
            );

            $engine->evaluate('stage_entered', $updated, $executedRuleIds, $depth + 1);
        } catch (ValidationException $e) {
            $this->logFailedAction($application, $action, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    private function executeRejectApplication(
        array $action,
        Application $application,
        AutomationRuleEngine $engine,
        array &$executedRuleIds,
        int $depth,
    ): void {
        $application->loadMissing(['jobOpening.hiringWorkflow.stages']);
        $stages        = $application->jobOpening->hiringWorkflow->stages;
        $rejectedStage = $stages->first(fn ($s) => $s->is_terminal && $s->stage_type === 'rejected');

        if ($rejectedStage === null) {
            $this->logFailedAction($application, $action, 'No rejected terminal stage found in workflow.');
            return;
        }

        try {
            $updated = $this->stageService->move(
                $application,
                $rejectedStage->id,
                $action['reason'] ?? 'Rejected by automation rule.',
                null,
                'automatic',
            );

            $engine->evaluate('stage_entered', $updated, $executedRuleIds, $depth + 1);
        } catch (ValidationException $e) {
            $this->logFailedAction($application, $action, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    private function executeMarkHired(
        array $action,
        Application $application,
        AutomationRuleEngine $engine,
        array &$executedRuleIds,
        int $depth,
    ): void {
        $application->loadMissing(['jobOpening.hiringWorkflow.stages']);
        $stages     = $application->jobOpening->hiringWorkflow->stages;
        $hiredStage = $stages->first(fn ($s) => $s->is_terminal && $s->stage_type === 'hired');

        if ($hiredStage === null) {
            $this->logFailedAction($application, $action, 'No hired terminal stage found in workflow.');
            return;
        }

        try {
            $updated = $this->stageService->move(
                $application,
                $hiredStage->id,
                $action['reason'] ?? 'Marked hired by automation rule.',
                null,
                'automatic',
            );

            $engine->evaluate('stage_entered', $updated, $executedRuleIds, $depth + 1);
        } catch (ValidationException $e) {
            $this->logFailedAction($application, $action, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    private function executeCreateActivity(array $action, Application $application): void
    {
        $application->loadMissing('jobOpening');

        $this->activityService->record(
            applicationId:   $application->id,
            storeId:         $application->jobOpening->store_id,
            eventType:       $action['event_type'] ?? 'automation_activity',
            workflowStageId: $application->current_stage_id,
            actorType:       'automation',
            actorId:         null,
            metadata:        $action['metadata'] ?? null,
        );
    }

    private function executePublishEvent(array $action, Application $application): void
    {
        $eventType = $action['event_type'] ?? 'hiring.automation.event';

        OutboxEvent::create([
            'event_id'   => Str::uuid()->toString(),
            'event_type' => $eventType,
            'subject'    => $eventType,
            'payload'    => array_merge(
                ['application_id' => $application->id],
                $action['payload'] ?? [],
            ),
            'status'   => 'pending',
            'attempts' => 0,
        ]);
    }

    private function executeSetScore(array $action, Application $application): void
    {
        $score = $action['score'] ?? null;
        if (! is_numeric($score)) {
            return;
        }

        DB::transaction(function () use ($application, $score, $action): void {
            $application->update(['score' => (float) $score]);
            $application->refresh();

            $application->loadMissing('jobOpening');
            $this->activityService->record(
                applicationId:   $application->id,
                storeId:         $application->jobOpening->store_id,
                eventType:       'score_set',
                workflowStageId: $application->current_stage_id,
                actorType:       'automation',
                actorId:         null,
                newValue:        ['score' => $application->score],
                metadata:        $action['metadata'] ?? null,
            );
        });
    }

    private function executeIncrementScore(array $action, Application $application): void
    {
        $by = $action['by'] ?? null;
        if (! is_numeric($by)) {
            return;
        }

        DB::transaction(function () use ($application, $by, $action): void {
            $application->refresh();
            $newScore = ((float) ($application->score ?? 0)) + (float) $by;
            $application->update(['score' => $newScore]);
            $application->refresh();

            $application->loadMissing('jobOpening');
            $this->activityService->record(
                applicationId:   $application->id,
                storeId:         $application->jobOpening->store_id,
                eventType:       'score_incremented',
                workflowStageId: $application->current_stage_id,
                actorType:       'automation',
                actorId:         null,
                newValue:        ['score' => $application->score, 'incremented_by' => $by],
                metadata:        $action['metadata'] ?? null,
            );
        });
    }

    private function logFailedAction(Application $application, array $action, string $reason): void
    {
        $application->loadMissing('jobOpening');

        $this->activityService->record(
            applicationId:   $application->id,
            storeId:         $application->jobOpening->store_id,
            eventType:       'automation_action_failed',
            workflowStageId: $application->current_stage_id,
            actorType:       'automation',
            actorId:         null,
            metadata:        ['action' => $action, 'reason' => $reason],
        );
    }
}
