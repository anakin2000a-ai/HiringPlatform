<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Questionnaires\CreateQuestionnaireTemplateRequest;
use App\Http\Requests\Questionnaires\UpdateQuestionnaireTemplateRequest;
use App\Http\Resources\QuestionnaireTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Questionnaires\QuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionnaireTemplateController extends Controller
{
    public function __construct(
        private readonly QuestionnaireService $questionnaireService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status'   => ['sometimes', 'string'],
            'search'   => ['sometimes', 'string', 'max:255'],
        ]);

        $query = QuestionnaireTemplate::where('store_id', $store->id)->with('questions','stageAssignments');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        $questionnaires = $query->orderBy('name')->orderBy('version')->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            QuestionnaireTemplateResource::collection($questionnaires)->response()->getData(true)
        );
    }

    public function store(CreateQuestionnaireTemplateRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request, $store)) {
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
        $questionnaire->load('questions','stageAssignments');
        return ApiResponse::success(
            new QuestionnaireTemplateResource($questionnaire->load('questions'))
        );
    }

    public function update(UpdateQuestionnaireTemplateRequest $request, Store $store, QuestionnaireTemplate $questionnaire): JsonResponse
    {
        if (! $this->questionnaireBelongsToStore($questionnaire, $store)) {
            return ApiResponse::notFound('Questionnaire not found');
        }

        if (! $this->canManage($request, $store)) {
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

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage questionnaires.');
        }

        $this->questionnaireService->delete($questionnaire);

        return ApiResponse::success(message: 'Questionnaire deleted');
    }

    private function questionnaireBelongsToStore(QuestionnaireTemplate $questionnaire, Store $store): bool
    {
        return $questionnaire->store_id === $store->id;
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
