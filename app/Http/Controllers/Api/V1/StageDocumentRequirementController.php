<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CreateStageDocumentRequirementRequest;
use App\Http\Requests\Documents\UpdateStageDocumentRequirementRequest;
use App\Http\Resources\StageDocumentRequirementResource;
use App\Http\Responses\ApiResponse;
use App\Models\StageDocumentRequirement;
use App\Models\WorkflowStage;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Documents\StageDocumentRequirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageDocumentRequirementController extends Controller
{
    public function __construct(
        private readonly StageDocumentRequirementService $requirementService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, WorkflowStage $stage): JsonResponse
    {
        $stage->load('workflow');
        $store = $stage->workflow->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        $request->validate([
            'per_page'             => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_required'          => ['sometimes', 'boolean'],
            'document_template_id' => ['sometimes', 'integer'],
        ]);

        $query = StageDocumentRequirement::where('workflow_stage_id', $stage->id)
            ->with('documentTemplate');

        if ($request->has('is_required')) {
            $query->where('is_required', $request->boolean('is_required'));
        }
        if ($request->filled('document_template_id')) {
            $query->where('document_template_id', $request->integer('document_template_id'));
        }

        $requirements = $query->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            StageDocumentRequirementResource::collection($requirements)->response()->getData(true)
        );
    }

    public function store(CreateStageDocumentRequirementRequest $request, WorkflowStage $stage): JsonResponse
    {
        $stage->load('workflow');
        $store = $stage->workflow->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $requirement = $this->requirementService->create($stage, $request->validated());

        return ApiResponse::created(
            new StageDocumentRequirementResource($requirement->load('documentTemplate')),
            'Stage document requirement created'
        );
    }

    public function show(Request $request, StageDocumentRequirement $requirement): JsonResponse
    {
        $requirement->load(['workflowStage.workflow.store', 'documentTemplate']);
        $store = $requirement->workflowStage->workflow->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        return ApiResponse::success(new StageDocumentRequirementResource($requirement));
    }

    public function update(UpdateStageDocumentRequirementRequest $request, StageDocumentRequirement $requirement): JsonResponse
    {
        $requirement->load(['workflowStage.workflow.store']);
        $store = $requirement->workflowStage->workflow->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $requirement = $this->requirementService->update($requirement, $request->validated());

        return ApiResponse::success(new StageDocumentRequirementResource($requirement->load('documentTemplate')));
    }

    public function destroy(Request $request, StageDocumentRequirement $requirement): JsonResponse
    {
        $requirement->load(['workflowStage.workflow.store']);
        $store = $requirement->workflowStage->workflow->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $this->requirementService->delete($requirement);

        return ApiResponse::success(message: 'Stage document requirement deleted');
    }

    private function canManage(Request $request, \App\Models\Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }
}
