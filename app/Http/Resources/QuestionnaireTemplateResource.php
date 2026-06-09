<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionnaireTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'store_id'   => $this->store_id,
            'name'       => $this->name,
            'version'    => $this->version,
            'status'     => $this->status,
            'created_by' => $this->created_by,
            'questions'  => QuestionnaireQuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
