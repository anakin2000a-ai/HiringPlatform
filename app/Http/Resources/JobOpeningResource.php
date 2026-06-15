<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobOpeningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'hiring_workflow_id' => $this->hiring_workflow_id,
            'title' => $this->title,
            'description' => $this->description,
            'employment_type' => $this->employment_type?->value,
            'openings_count' => $this->openings_count,
            'status' => $this->status?->value,
            'published_at' => $this->published_at,
            'closed_at' => $this->closed_at,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
