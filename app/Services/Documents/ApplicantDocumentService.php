<?php

namespace App\Services\Documents;

use App\Enums\ActorType;
use App\Enums\DocumentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\WorkflowEventType;
use App\Models\Application;
use App\Models\ApplicantDocument;
use App\Models\OutboxEvent;
use App\Models\StageDocumentRequirement;
use App\Models\User;
use App\Services\Applications\WorkflowActivityService;
use App\Services\Automation\AutomationRuleEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicantDocumentService
{
    public function __construct(
        private readonly WorkflowActivityService $activityService,
        private readonly AutomationRuleEngine $automationEngine,
    ) {}

    public function initiate(Application $application, StageDocumentRequirement $requirement): ApplicantDocument
    {
        return ApplicantDocument::create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $requirement->workflow_stage_id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $requirement->document_template_id,
            'status'                       => DocumentStatus::Pending,
        ]);
    }

   public function submit(ApplicantDocument $document, User $actor, UploadedFile $file): ApplicantDocument
{
    if (! in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Rejected], true)) {
        throw ValidationException::withMessages([
            'status' => ['Only pending or rejected documents can be submitted.'],
        ]);
    }

    $result = DB::transaction(function () use ($document, $actor, $file): ApplicantDocument {
        $path = $file->store("applicant-documents/{$document->application_id}", 'public');

        $document->update([
            'status'       => DocumentStatus::Submitted,
            'file_path'    => $path,
            'submitted_at' => now(),
        ]);

        $storeId = $document->application->jobOpening->store_id;

        $this->activityService->record(
            applicationId:  $document->application_id,
            storeId:        $storeId,
            eventType:      WorkflowEventType::ApplicantDocumentSubmitted,
            workflowStageId: $document->workflow_stage_id,
            actorType:      ActorType::User,
            actorId:        $actor->id,
            newValue: [
                'document_id' => $document->id,
                'status'      => DocumentStatus::Submitted->value,
                'file_path'   => $path,
            ],
        );

        OutboxEvent::create([
            'event_id'   => Str::uuid()->toString(),
            'event_type' => 'hiring.document.submitted',
            'subject'    => 'hiring.document.submitted',
            'payload'    => [
                'document_id'    => $document->id,
                'application_id' => $document->application_id,
                'submitted_by'   => $actor->id,
                'file_path'      => $path,
            ],
            'status'   => OutboxEventStatus::Pending,
            'attempts' => 0,
        ]);

        return $document->fresh(['documentTemplate']);
    });

    $this->automationEngine->evaluate('document_submitted', $result->application);

    return $result;
}

    public function sign(ApplicantDocument $document, ?string $externalSignatureId, User $actor): ApplicantDocument
    {
        if ($document->status !== DocumentStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted documents can be signed.'],
            ]);
        }

        $template = $document->documentTemplate ?? $document->load('documentTemplate')->documentTemplate;

        if (! $template->requires_signature) {
            throw ValidationException::withMessages([
                'status' => ['This document does not require a signature.'],
            ]);
        }

        $result = DB::transaction(function () use ($document, $externalSignatureId, $actor): ApplicantDocument {
            $document->update([
                'status'                => DocumentStatus::Signed,
                'signed_at'             => now(),
                'external_signature_id' => $externalSignatureId,
            ]);

            $storeId = $document->application->jobOpening->store_id;

            $this->activityService->record(
                applicationId:  $document->application_id,
                storeId:        $storeId,
                eventType:      WorkflowEventType::ApplicantDocumentSigned,
                workflowStageId: $document->workflow_stage_id,
                actorType:      ActorType::User,
                actorId:        $actor->id,
                newValue:       ['document_id' => $document->id, 'status' => DocumentStatus::Signed->value],
            );

            OutboxEvent::create([
                'event_id'   => Str::uuid()->toString(),
                'event_type' => 'hiring.document.signed',
                'subject'    => 'hiring.document.signed',
                'payload'    => [
                    'document_id'    => $document->id,
                    'application_id' => $document->application_id,
                    'signed_by'      => $actor->id,
                ],
                'status'   => OutboxEventStatus::Pending,
                'attempts' => 0,
            ]);

            return $document->fresh(['documentTemplate']);
        });

        $this->automationEngine->evaluate('document_signed', $result->application);

        return $result;
    }

    public function approve(ApplicantDocument $document, User $actor): ApplicantDocument
    {
        $template = $document->documentTemplate ?? $document->load('documentTemplate')->documentTemplate;

        $allowedStatuses = $template->requires_signature
            ? [DocumentStatus::Signed]
            : [DocumentStatus::Submitted, DocumentStatus::Signed];

        if (! in_array($document->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => ['Document cannot be approved from its current status.'],
            ]);
        }

        $result = DB::transaction(function () use ($document, $actor): ApplicantDocument {
            $document->update([
                'status'      => DocumentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $storeId = $document->application->jobOpening->store_id;

            $this->activityService->record(
                applicationId:  $document->application_id,
                storeId:        $storeId,
                eventType:      WorkflowEventType::ApplicantDocumentApproved,
                workflowStageId: $document->workflow_stage_id,
                actorType:      ActorType::User,
                actorId:        $actor->id,
                newValue:       ['document_id' => $document->id, 'status' => DocumentStatus::Approved->value],
            );

            OutboxEvent::create([
                'event_id'   => Str::uuid()->toString(),
                'event_type' => 'hiring.document.approved',
                'subject'    => 'hiring.document.approved',
                'payload'    => [
                    'document_id'    => $document->id,
                    'application_id' => $document->application_id,
                    'approved_by'    => $actor->id,
                ],
                'status'   => OutboxEventStatus::Pending,
                'attempts' => 0,
            ]);

            return $document->fresh(['documentTemplate']);
        });

        $this->automationEngine->evaluate('document_approved', $result->application);

        return $result;
    }

    public function reject(ApplicantDocument $document, string $reason, User $actor): ApplicantDocument
    {
        if ($document->status === DocumentStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['Approved documents cannot be rejected.'],
            ]);
        }

        $result = DB::transaction(function () use ($document, $reason, $actor): ApplicantDocument {
            $document->update([
                'status'          => DocumentStatus::Rejected,
                'rejected_at'     => now(),
                'rejected_reason' => $reason,
            ]);

            $storeId = $document->application->jobOpening->store_id;

            $this->activityService->record(
                applicationId:  $document->application_id,
                storeId:        $storeId,
                eventType:      WorkflowEventType::ApplicantDocumentRejected,
                workflowStageId: $document->workflow_stage_id,
                actorType:      ActorType::User,
                actorId:        $actor->id,
                newValue:       ['document_id' => $document->id, 'status' => DocumentStatus::Rejected->value, 'reason' => $reason],
            );

            OutboxEvent::create([
                'event_id'   => Str::uuid()->toString(),
                'event_type' => 'hiring.document.rejected',
                'subject'    => 'hiring.document.rejected',
                'payload'    => [
                    'document_id'    => $document->id,
                    'application_id' => $document->application_id,
                    'rejected_by'    => $actor->id,
                    'reason'         => $reason,
                ],
                'status'   => OutboxEventStatus::Pending,
                'attempts' => 0,
            ]);

            return $document->fresh(['documentTemplate']);
        });

        $this->automationEngine->evaluate('document_rejected', $result->application);

        return $result;
    }
}
