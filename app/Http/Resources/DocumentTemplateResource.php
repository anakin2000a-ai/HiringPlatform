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
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
