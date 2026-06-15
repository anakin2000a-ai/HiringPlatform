<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hiring_workflow_id' => $this->hiring_workflow_id,
            'name' => $this->name,
            'stage_type' => $this->stage_type?->value,
            'position' => $this->position,
            'is_initial' => $this->is_initial,
            'is_terminal' => $this->is_terminal,
            'auto_advance_enabled' => $this->auto_advance_enabled,
            'configuration' => $this->configuration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
