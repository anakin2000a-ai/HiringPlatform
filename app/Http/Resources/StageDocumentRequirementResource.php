<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageDocumentRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'workflow_stage_id'           => $this->workflow_stage_id,
            'document_template_id'        => $this->document_template_id,
            'is_required'                 => $this->is_required,
            'due_days_after_stage_entry'  => $this->due_days_after_stage_entry,
            'document_template'           => $this->whenLoaded('documentTemplate', fn () => new DocumentTemplateResource($this->documentTemplate)),
            'created_at'                  => $this->created_at,
            'updated_at'                  => $this->updated_at,
        ];
    }
}
