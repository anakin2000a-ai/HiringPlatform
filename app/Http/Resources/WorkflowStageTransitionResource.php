<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowStageTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hiring_workflow_id' => $this->hiring_workflow_id,
            'from_stage_id' => $this->from_stage_id,
            'to_stage_id' => $this->to_stage_id,
            'name' => $this->name,
            'is_manual_allowed' => $this->is_manual_allowed,
            'is_automatic_allowed' => $this->is_automatic_allowed,
            'conditions' => $this->conditions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
