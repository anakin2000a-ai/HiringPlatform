<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'application_id'   => $this->application_id,
            'store_id'         => $this->store_id,
            'workflow_stage_id' => $this->workflow_stage_id,
            'actor_type'       => $this->actor_type?->value,
            'actor_id'         => $this->actor_id,
            'event_type'       => $this->event_type,
            'old_value'        => $this->old_value,
            'new_value'        => $this->new_value,
            'metadata'         => $this->metadata,
            'created_at'       => $this->created_at,
        ];
    }
}
