<?php

namespace App\Services\Questionnaires;

use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use Illuminate\Validation\ValidationException;

class QuestionnaireQuestionService
{
    public function create(QuestionnaireTemplate $questionnaire, array $data): QuestionnaireQuestion
    {
        $exists = QuestionnaireQuestion::where('questionnaire_template_id', $questionnaire->id)
            ->where('question_key', $data['question_key'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'question_key' => ['A question with this key already exists in this questionnaire.'],
            ]);
        }

        return QuestionnaireQuestion::create([
            'questionnaire_template_id' => $questionnaire->id,
            'question_key'              => $data['question_key'],
            'label'                     => $data['label'],
            'type'                      => $data['type'],
            'options'                   => $data['options'] ?? null,
            'validation_rules'          => $data['validation_rules'] ?? null,
            'visibility_rules'          => $data['visibility_rules'] ?? null,
            'is_required'               => $data['is_required'] ?? false,
            'position'                  => $data['position'],
        ]);
    }

    public function update(QuestionnaireQuestion $question, array $data): QuestionnaireQuestion
    {
        if (isset($data['question_key']) && $data['question_key'] !== $question->question_key) {
            $exists = QuestionnaireQuestion::where('questionnaire_template_id', $question->questionnaire_template_id)
                ->where('question_key', $data['question_key'])
                ->where('id', '!=', $question->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'question_key' => ['A question with this key already exists in this questionnaire.'],
                ]);
            }
        }

        $allowed = collect($data)->only([
            'question_key', 'label', 'type', 'options',
            'validation_rules', 'visibility_rules', 'is_required', 'position',
        ])->all();

        $question->update($allowed);

        return $question->fresh();
    }

    public function delete(QuestionnaireQuestion $question): void
    {
        $question->delete();
    }
}
