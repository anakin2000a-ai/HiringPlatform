<?php

namespace App\Services\Questionnaires;

use App\Models\ApplicantAnswer;
use App\Models\Application;
use App\Models\OutboxEvent;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Models\User;
use App\Services\Applications\WorkflowActivityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicantAnswerService
{
    public function __construct(private readonly WorkflowActivityService $activityService) {}

    public function submit(Application $application, Store $store, array $data, User $actor): Collection
    {
        $questionnaireId = $data['questionnaire_template_id'];

        $questionnaire = QuestionnaireTemplate::where('id', $questionnaireId)
            ->where('store_id', $store->id)
            ->first();

        if ($questionnaire === null) {
            throw ValidationException::withMessages([
                'questionnaire_template_id' => ['The questionnaire template does not belong to this store.'],
            ]);
        }

        $questions = QuestionnaireQuestion::where('questionnaire_template_id', $questionnaireId)
            ->get()
            ->keyBy('id');

        $submittedQuestionIds = array_column($data['answers'], 'questionnaire_question_id');

        foreach ($submittedQuestionIds as $qid) {
            if (! $questions->has($qid)) {
                throw ValidationException::withMessages([
                    'answers' => ["Question {$qid} does not belong to questionnaire {$questionnaireId}."],
                ]);
            }
        }

        $requiredQuestions = $questions->filter(fn (QuestionnaireQuestion $q) => $q->is_required);

        foreach ($requiredQuestions as $required) {
            if (! in_array($required->id, $submittedQuestionIds, true)) {
                throw ValidationException::withMessages([
                    'answers' => ["Question \"{$required->label}\" is required."],
                ]);
            }
        }

        return DB::transaction(function () use ($application, $store, $questionnaire, $data, $actor): Collection {
            $savedAnswers = collect();

            foreach ($data['answers'] as $answerData) {
                $answer = ApplicantAnswer::updateOrCreate(
                    [
                        'application_id'           => $application->id,
                        'questionnaire_question_id' => $answerData['questionnaire_question_id'],
                    ],
                    [
                        'questionnaire_template_id' => $questionnaire->id,
                        'answer'                    => $answerData['answer'],
                        'answered_at'               => now(),
                    ]
                );

                $savedAnswers->push($answer);
            }

            $this->activityService->record(
                applicationId: $application->id,
                storeId: $store->id,
                eventType: 'answers_submitted',
                workflowStageId: $application->current_stage_id,
                actorType: 'user',
                actorId: $actor->id,
                newValue: [
                    'questionnaire_template_id' => $questionnaire->id,
                    'answer_count'              => $savedAnswers->count(),
                ],
                metadata: ['questionnaire_name' => $questionnaire->name],
            );

            OutboxEvent::create([
                'event_id'   => Str::uuid()->toString(),
                'event_type' => 'hiring.questionnaire.submitted',
                'subject'    => 'hiring.questionnaire.submitted',
                'payload'    => [
                    'application_id'           => $application->id,
                    'questionnaire_template_id' => $questionnaire->id,
                    'answer_count'              => $savedAnswers->count(),
                ],
                'status'   => 'pending',
                'attempts' => 0,
            ]);

            return $savedAnswers;
        });
    }
}
