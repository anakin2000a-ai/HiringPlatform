<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\ApplicantDocument;
use App\Models\Application;
use App\Models\DocumentTemplate;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicantDocument>
 */
class ApplicantDocumentFactory extends Factory
{
    protected $model = ApplicantDocument::class;

    public function definition(): array
    {
        return [
            'application_id'              => Application::factory(),
            'workflow_stage_id'           => WorkflowStage::factory(),
            'stage_document_requirement_id' => null,
            'document_template_id'        => DocumentTemplate::factory(),
            'status'                      => DocumentStatus::Pending,
            'file_path'                   => null,
            'external_signature_id'       => null,
            'submitted_at'                => null,
            'signed_at'                   => null,
            'approved_by'                 => null,
            'approved_at'                 => null,
            'rejected_at'                 => null,
            'rejected_reason'             => null,
            'expires_at'                  => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => DocumentStatus::Pending]);
    }

    public function submitted(): static
    {
        return $this->state(['status' => DocumentStatus::Submitted, 'submitted_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(['status' => DocumentStatus::Approved, 'submitted_at' => now(), 'approved_at' => now()]);
    }
}
