<?php

namespace App\Services\Applications;

use App\Enums\ActorType;
use App\Enums\WorkflowEventType;
use App\Models\WorkflowActivity;

class WorkflowActivityService
{
    public function record(
        int $applicationId,
        int $storeId,
        string|WorkflowEventType $eventType,
        ?int $workflowStageId = null,
        ActorType|string|null $actorType = null,
        ?int $actorId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?array $metadata = null,
    ): WorkflowActivity {
        return WorkflowActivity::create([
            'application_id'    => $applicationId,
            'store_id'          => $storeId,
            'workflow_stage_id' => $workflowStageId,
            'actor_type'        => $actorType,
            'actor_id'          => $actorId,
            'event_type'        => $eventType instanceof WorkflowEventType ? $eventType->value : $eventType,
            'old_value'         => $oldValue,
            'new_value'         => $newValue,
            'metadata'          => $metadata,
            'created_at'        => now(),
        ]);
    }
}
