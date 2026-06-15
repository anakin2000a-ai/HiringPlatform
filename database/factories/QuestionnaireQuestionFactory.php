<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionnaireQuestion>
 */
class QuestionnaireQuestionFactory extends Factory
{
    protected $model = QuestionnaireQuestion::class;

    public function definition(): array
    {
        return [
            'questionnaire_template_id' => QuestionnaireTemplate::factory(),
            'question_key'              => $this->faker->unique()->slug(2),
            'label'                     => $this->faker->sentence() . '?',
            'type'                      => $this->faker->randomElement([QuestionType::Text, QuestionType::Boolean, QuestionType::Select]),
            'options'                   => null,
            'validation_rules'          => null,
            'visibility_rules'          => null,
            'is_required'               => false,
            'position'                  => $this->faker->numberBetween(1, 20),
        ];
    }

    public function forQuestionnaire(QuestionnaireTemplate $questionnaire): static
    {
        return $this->state(['questionnaire_template_id' => $questionnaire->id]);
    }

    public function required(): static
    {
        return $this->state(['is_required' => true]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type]);
    }
}
