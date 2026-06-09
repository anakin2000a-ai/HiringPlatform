<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Questionnaires\CreateQuestionnaireQuestionRequest;
use App\Http\Requests\Questionnaires\UpdateQuestionnaireQuestionRequest;
use App\Http\Resources\QuestionnaireQuestionResource;
use App\Http\Responses\ApiResponse;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Services\Questionnaires\QuestionnaireQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionnaireQuestionController extends Controller
{
    public function __construct(private readonly QuestionnaireQuestionService $questionService) {}

    public function index(Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        $questions = $questionnaire->questions()->get();

        return ApiResponse::success(QuestionnaireQuestionResource::collection($questions));
    }

    public function store(CreateQuestionnaireQuestionRequest $request, Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaire questions.');
        }

        $question = $this->questionService->create($questionnaire, $request->validated());

        return ApiResponse::created(new QuestionnaireQuestionResource($question), 'Question created');
    }

    public function update(UpdateQuestionnaireQuestionRequest $request, Store $store, QuestionnaireTemplate $questionnaire, QuestionnaireQuestion $question): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->questionBelongsToQuestionnaire($question, $questionnaire)) {
            return ApiResponse::notFound('Question not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaire questions.');
        }

        $question = $this->questionService->update($question, $request->validated());

        return ApiResponse::success(new QuestionnaireQuestionResource($question));
    }

    public function destroy(Request $request, Store $store, QuestionnaireTemplate $questionnaire, QuestionnaireQuestion $question): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->questionBelongsToQuestionnaire($question, $questionnaire)) {
            return ApiResponse::notFound('Question not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaire questions.');
        }

        $this->questionService->delete($question);

        return ApiResponse::success(message: 'Question deleted');
    }

    private function questionnaireBelongsToStore(QuestionnaireTemplate $questionnaire, Store $store): bool
    {
        return $questionnaire->store_id === $store->id;
    }

    private function questionBelongsToQuestionnaire(QuestionnaireQuestion $question, QuestionnaireTemplate $questionnaire): bool
    {
        return $question->questionnaire_template_id === $questionnaire->id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
