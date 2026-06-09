<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationStageTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'application_id'  => $this->application_id,
            'from_stage_id'   => $this->from_stage_id,
            'to_stage_id'     => $this->to_stage_id,
            'changed_by'      => $this->changed_by,
            'transition_type' => $this->transition_type,
            'reason'          => $this->reason,
            'metadata'        => $this->metadata,
            'created_at'      => $this->created_at,
            'from_stage'      => new WorkflowStageResource($this->whenLoaded('fromStage')),
            'to_stage'        => new WorkflowStageResource($this->whenLoaded('toStage')),
        ];
    }
}
