<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HiringWorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'version' => $this->version,
            'status' => $this->status,
            'parent_workflow_id' => $this->parent_workflow_id,
            'created_by' => $this->created_by,
            'published_at' => $this->published_at,
            'archived_at' => $this->archived_at,
            'stages' => WorkflowStageResource::collection($this->whenLoaded('stages')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
