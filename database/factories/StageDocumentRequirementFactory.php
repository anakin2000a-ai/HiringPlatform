<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageDocumentRequirement>
 */
class StageDocumentRequirementFactory extends Factory
{
    protected $model = StageDocumentRequirement::class;

    public function definition(): array
    {
        return [
            'workflow_stage_id'          => WorkflowStage::factory(),
            'document_template_id'       => DocumentTemplate::factory(),
            'is_required'                => true,
            'due_days_after_stage_entry' => null,
        ];
    }

    public function optional(): static
    {
        return $this->state(['is_required' => false]);
    }
}
