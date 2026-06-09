<?php

namespace Database\Factories;

use App\Models\QuestionnaireTemplate;
use App\Models\StageQuestionnaireAssignment;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageQuestionnaireAssignment>
 */
class StageQuestionnaireAssignmentFactory extends Factory
{
    protected $model = StageQuestionnaireAssignment::class;

    public function definition(): array
    {
        return [
            'workflow_stage_id'         => WorkflowStage::factory(),
            'questionnaire_template_id' => QuestionnaireTemplate::factory(),
            'is_required'               => true,
        ];
    }

    public function optional(): static
    {
        return $this->state(['is_required' => false]);
    }
}
