<?php

namespace App\Services\Applications;

use App\Enums\ActorType;
use App\Enums\ApplicationStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StageType;
use App\Enums\TransitionType;
use App\Enums\WorkflowEventType;
use App\Jobs\PublishHiringOutboxEventJob;
use App\Models\Application;
use App\Models\ApplicationStageTransition;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use App\Services\HiringEvents\HiringEventFactory;
use App\Services\HiringEvents\HiringOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicationStageService
{
    public function __construct(
        private readonly WorkflowActivityService $activityService,
    ) {}

    public function move(
        Application $application,
        int $toStageId,
        ?string $reason,
        ?User $actor,
        TransitionType $transitionType = TransitionType::Manual,
    ): Application {
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

        $fromStageId   = $application->current_stage_id;
        $allowedColumn = $transitionType === TransitionType::Automatic ? 'is_automatic_allowed' : 'is_manual_allowed';

        $transition = WorkflowStageTransition::where('hiring_workflow_id', $workflow->id)
            ->where('from_stage_id', $fromStageId)
            ->where('to_stage_id', $toStageId)
            ->where($allowedColumn, true)
            ->first();

        if ($transition === null) {
            throw ValidationException::withMessages([
                'to_stage_id' => ['This stage transition is not allowed.'],
            ]);
        }

        return DB::transaction(function () use ($application, $toStage, $fromStageId, $reason, $actor, $transitionType): Application {
            $updates = ['current_stage_id' => $toStage->id];

            if ($toStage->is_terminal) {
                if ($toStage->stage_type === StageType::Hired && $application->hired_at === null) {
                    $updates['status']   = ApplicationStatus::Hired;
                    $updates['hired_at'] = now();
                } elseif ($toStage->stage_type === StageType::Rejected && $application->rejected_at === null) {
                    $updates['status']      = ApplicationStatus::Rejected;
                    $updates['rejected_at'] = now();
                }
            }

            $application->update($updates);

            $actorType = $transitionType === TransitionType::Automatic ? ActorType::Automation : ActorType::User;

            ApplicationStageTransition::create([
                'application_id'  => $application->id,
                'from_stage_id'   => $fromStageId,
                'to_stage_id'     => $toStage->id,
                'changed_by'      => $actor?->id,
                'transition_type' => $transitionType,
                'reason'          => $reason,
                'metadata'        => null,
                'created_at'      => now(),
            ]);

            $storeId = $application->jobOpening->store_id;

            $this->activityService->record(
                applicationId: $application->id,
                storeId: $storeId,
                eventType: WorkflowEventType::StageMoved,
                workflowStageId: $toStage->id,
                actorType: $actorType,
                actorId: $actor?->id,
                oldValue: ['stage_id' => $fromStageId],
                newValue: ['stage_id' => $toStage->id, 'stage_name' => $toStage->name],
                metadata: ['reason' => $reason],
            );

            OutboxEvent::create([
                'event_id'   => Str::uuid()->toString(),
                'event_type' => 'hiring.application.stage_changed',
                'subject'    => 'hiring.application.stage_changed',
                'payload'    => [
                    'application_id'  => $application->id,
                    'from_stage_id'   => $fromStageId,
                    'to_stage_id'     => $toStage->id,
                    'moved_by'        => $actor?->id,
                    'transition_type' => $transitionType,
                    'status'          => $application->status,
                ],
                'status'   => OutboxEventStatus::Pending,
                'attempts' => 0,
            ]);

            // Emit a versioned business event when the application reaches a NEW terminal outcome.
            // Duplicate guard: only emit if this move actually set the terminal timestamp.
            if ($toStage->is_terminal && isset($updates[$toStage->stage_type->value . '_at'])) {
                $application->loadMissing(['applicant', 'jobOpening.store']);

                $decision  = $toStage->stage_type->value; // 'hired' or 'rejected'
                $applicant = $application->applicant;
                $job       = $application->jobOpening;
                $store     = $job?->store;
                $decidedAt = $application->{"${decision}_at"} ?? now();

                $subject = match ($decision) {
                    'hired'    => 'hiring.v1.application.hired',
                    'rejected' => 'hiring.v1.application.rejected',
                    default    => null,
                };

                if ($subject !== null) {
                    $applicantName = $applicant
                        ? trim(($applicant->first_name ?? '') . ' ' . ($applicant->last_name ?? ''))
                        : null;

                    $data = [
                        'application_id'       => $application->id,
                        'applicant_id'         => $application->applicant_id,
                        'applicant_name'       => $applicantName ?: null,
                        'applicant_email'      => $applicant?->email,
                        'applicant_phone'      => $applicant?->phone,
                        'job_opening_id'       => $application->job_opening_id,
                        'job_title'            => $job?->title,
                        'store_id'             => $job?->store_id,
                        'store_name'           => $store?->store_name,
                        'franchise_account_id' => $store?->franchise_account_id,
                        'previous_stage_id'    => $fromStageId,
                        'current_stage_id'     => $toStage->id,
                        'current_stage_name'   => $toStage->name,
                        'status'               => $decision,
                        'decision'             => $decision,
                        'decided_at'           => $decidedAt instanceof \DateTimeInterface
                            ? $decidedAt->toIso8601String()
                            : (string) $decidedAt,
                        'decided_by_user_id'   => $actor?->id,
                        'applicant'            => $applicant ? [
                            'first_name' => $applicant->first_name,
                            'last_name'  => $applicant->last_name,
                            'email'      => $applicant->email,
                            'phone'      => $applicant->phone,
                        ] : null,
                    ];

                    $this->recordEvent($subject, $data);
                }
            }

            return $application->fresh(['applicant', 'jobOpening', 'currentStage']);
        });
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory  = app(HiringEventFactory::class);
        $outbox   = app(HiringOutboxService::class);
        $envelope = $factory->make($subject, $data, null);
        $row      = $outbox->record($subject, $envelope);
        DB::afterCommit(fn () => PublishHiringOutboxEventJob::dispatch((string) $row->id));
    }
}
