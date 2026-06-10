<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                            => $this->id,
            'application_id'               => $this->application_id,
            'workflow_stage_id'            => $this->workflow_stage_id,
            'stage_document_requirement_id' => $this->stage_document_requirement_id,
            'document_template_id'         => $this->document_template_id,
            'status'                       => $this->status,
            'file_path'                    => $this->file_path,
            'external_signature_id'        => $this->external_signature_id,
            'submitted_at'                 => $this->submitted_at,
            'signed_at'                    => $this->signed_at,
            'approved_by'                  => $this->approved_by,
            'approved_at'                  => $this->approved_at,
            'rejected_at'                  => $this->rejected_at,
            'rejected_reason'              => $this->rejected_reason,
            'expires_at'                   => $this->expires_at,
            'document_template'            => $this->whenLoaded('documentTemplate', fn () => new DocumentTemplateResource($this->documentTemplate)),
            'created_at'                   => $this->created_at,
            'updated_at'                   => $this->updated_at,
        ];
    }
}
