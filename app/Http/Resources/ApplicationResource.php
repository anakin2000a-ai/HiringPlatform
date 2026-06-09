<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'applicant_id' => $this->applicant_id,
            'job_opening_id' => $this->job_opening_id,
            'current_stage_id' => $this->current_stage_id,
            'status' => $this->status,
            'score' => $this->score,
            'applied_at' => $this->applied_at,
            'rejected_at' => $this->rejected_at,
            'hired_at' => $this->hired_at,
            'withdrawn_at' => $this->withdrawn_at,
            'applicant' => new ApplicantResource($this->whenLoaded('applicant')),
            'current_stage' => new WorkflowStageResource($this->whenLoaded('currentStage')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
