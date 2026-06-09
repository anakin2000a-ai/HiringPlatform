<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\ApplicationStageTransition;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicationStageService
{
    public function __construct(private readonly WorkflowActivityService $activityService) {}

    public function move(Application $application, int $toStageId, ?string $reason, User $actor): Application
    {
        $application->load(['jobOpening.hiringWorkflow', 'currentStage']);

        $workflow = $application->jobOpening->hiringWorkflow;

        $toStage = WorkflowStage::where('id', $toStageId)
            ->where('hiring_workflow_id', $workflow->id)
            ->first();

        if ($toStage === null) {
            throw ValidationException::withMessages([
                'to_stage_id' => ['The target stage does not belong to this application\'s workflow.'],
            ]);
        }

        $fromStageId = $application->current_stage_id;

        $transition = WorkflowStageTransition::where('hiring_workflow_id', $workflow->id)
            ->where('from_stage_id', $fromStageId)
            ->where('to_stage_id', $toStageId)
            ->where('is_manual_allowed', true)
            ->first();

        if ($transition === null) {
            throw ValidationException::withMessages([
                'to_stage_id' => ['This stage transition is not allowed.'],
            ]);
        }

        return DB::transaction(function () use ($application, $toStage, $fromStageId, $reason, $actor): Application {
            $updates = ['current_stage_id' => $toStage->id];

            if ($toStage->is_terminal) {
                if ($toStage->stage_type === 'hired' && $application->hired_at === null) {
                    $updates['status'] = 'hired';
                    $updates['hired_at'] = now();
                } elseif ($toStage->stage_type === 'rejected' && $application->rejected_at === null) {
                    $updates['status'] = 'rejected';
                    $updates['rejected_at'] = now();
                }
            }

            $application->update($updates);

            ApplicationStageTransition::create([
                'application_id'  => $application->id,
                'from_stage_id'   => $fromStageId,
                'to_stage_id'     => $toStage->id,
                'changed_by'      => $actor->id,
                'transition_type' => 'manual',
                'reason'          => $reason,
                'metadata'        => null,
                'created_at'      => now(),
            ]);

            $storeId = $application->jobOpening->store_id;

            $this->activityService->record(
                applicationId: $application->id,
                storeId: $storeId,
                eventType: 'stage_moved',
                workflowStageId: $toStage->id,
                actorType: 'user',
                actorId: $actor->id,
                oldValue: ['stage_id' => $fromStageId],
                newValue: ['stage_id' => $toStage->id, 'stage_name' => $toStage->name],
                metadata: ['reason' => $reason],
            );

            OutboxEvent::create([
                'event_id'   => Str::uuid()->toString(),
                'event_type' => 'hiring.application.stage_changed',
                'subject'    => 'hiring.application.stage_changed',
                'payload'    => [
                    'application_id' => $application->id,
                    'from_stage_id'  => $fromStageId,
                    'to_stage_id'    => $toStage->id,
                    'moved_by'       => $actor->id,
                    'status'         => $application->status,
                ],
                'status'   => 'pending',
                'attempts' => 0,
            ]);

            return $application->fresh(['applicant', 'jobOpening', 'currentStage']);
        });
    }
}
