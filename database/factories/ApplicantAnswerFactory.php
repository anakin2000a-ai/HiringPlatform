<?php

namespace Database\Factories;

use App\Models\ApplicantAnswer;
use App\Models\Application;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicantAnswer>
 */
class ApplicantAnswerFactory extends Factory
{
    protected $model = ApplicantAnswer::class;

    public function definition(): array
    {
        return [
            'application_id'            => Application::factory(),
            'questionnaire_template_id' => QuestionnaireTemplate::factory(),
            'questionnaire_question_id' => QuestionnaireQuestion::factory(),
            'answer'                    => $this->faker->word(),
            'answered_at'               => now(),
        ];
    }

    public function withAnswer(mixed $answer): static
    {
        return $this->state(['answer' => $answer]);
    }
}
