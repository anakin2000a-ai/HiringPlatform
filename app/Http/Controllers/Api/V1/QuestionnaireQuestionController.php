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
use App\Services\AccessControl\StoreAccessService;
use App\Services\Questionnaires\QuestionnaireQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionnaireQuestionController extends Controller
{
    public function __construct(
        private readonly QuestionnaireQuestionService $questionService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        $request->validate([
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type'        => ['sometimes', 'string'],
            'is_required' => ['sometimes', 'boolean'],
            'search'      => ['sometimes', 'string', 'max:255'],
        ]);

        $query = $questionnaire->questions();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->has('is_required')) {
            $query->where('is_required', $request->boolean('is_required'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q
                ->where('label', 'like', $term)
                ->orWhere('question_key', 'like', $term)
            );
        }

        $questions = $query->orderBy('position')->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            QuestionnaireQuestionResource::collection($questions)->response()->getData(true)
        );
    }

    public function store(CreateQuestionnaireQuestionRequest $request, Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->canManage($request, $store)) {
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

        if (! $this->canManage($request, $store)) {
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

        if (! $this->canManage($request, $store)) {
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

    private function canManage(Request $request, Store $store): bool
    {
        return in_array(
            $this->storeAccessService->getUserRoleAtStore($request->user(), $store),
            ['franchise_admin', 'store_manager'],
            true
        );
    }
}
