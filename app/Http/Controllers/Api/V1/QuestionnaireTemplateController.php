<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Questionnaires\CreateQuestionnaireTemplateRequest;
use App\Http\Requests\Questionnaires\UpdateQuestionnaireTemplateRequest;
use App\Http\Resources\QuestionnaireTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Services\Questionnaires\QuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionnaireTemplateController extends Controller
{
    public function __construct(private readonly QuestionnaireService $questionnaireService) {}

    public function index(Store $store): JsonResponse
    {
        $questionnaires = QuestionnaireTemplate::where('store_id', $store->id)
            ->orderBy('name')
            ->paginate(20);

        return ApiResponse::success(
            QuestionnaireTemplateResource::collection($questionnaires)->response()->getData(true)
        );
    }

    public function store(CreateQuestionnaireTemplateRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaires.');
        }

        $questionnaire = $this->questionnaireService->create($store, $request->validated(), $request->user());

        return ApiResponse::created(new QuestionnaireTemplateResource($questionnaire), 'Questionnaire created');
    }

    public function show(Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        return ApiResponse::success(
            new QuestionnaireTemplateResource($questionnaire->load('questions'))
        );
    }

    public function update(UpdateQuestionnaireTemplateRequest $request, Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaires.');
        }

        $questionnaire = $this->questionnaireService->update($questionnaire, $request->validated());

        return ApiResponse::success(new QuestionnaireTemplateResource($questionnaire));
    }

    public function destroy(Request $request, Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaires.');
        }

        $this->questionnaireService->delete($questionnaire);

        return ApiResponse::success(message: 'Questionnaire deleted');
    }

    private function questionnaireBelongsToStore(QuestionnaireTemplate $questionnaire, Store $store): bool
    {
        return $questionnaire->store_id === $store->id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
