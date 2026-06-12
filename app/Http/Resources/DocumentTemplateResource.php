<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'store_id'           => $this->store_id,
            'name'               => $this->name,
            'document_type'      => $this->document_type,
            'requires_signature' => $this->requires_signature,
            'description'        => $this->description,
            'created_by'         => $this->created_by,
            'requirements'       => $this->whenLoaded('stageRequirements', fn () => $this->stageRequirements->map(fn ($r) => [
                'id'                         => $r->id,
                'workflow_stage_id'          => $r->workflow_stage_id,
                'is_required'               => $r->is_required,
                'due_days_after_stage_entry' => $r->due_days_after_stage_entry,
            ])),
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
