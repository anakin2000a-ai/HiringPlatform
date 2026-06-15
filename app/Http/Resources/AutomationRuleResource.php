<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'store_id'            => $this->store_id,
            'hiring_workflow_id'  => $this->hiring_workflow_id,
            'workflow_stage_id'   => $this->workflow_stage_id,
            'name'                => $this->name,
            'trigger'             => $this->trigger?->value,
            'conditions'          => $this->conditions,
            'actions'             => $this->actions,
            'priority'            => $this->priority,
            'is_active'           => $this->is_active,
            'created_by'          => $this->created_by,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
