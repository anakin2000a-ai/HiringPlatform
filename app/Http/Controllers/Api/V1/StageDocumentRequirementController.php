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

        $requirements = StageDocumentRequirement::where('workflow_stage_id', $stage->id)
            ->with('documentTemplate')
            ->get();

        return ApiResponse::success(StageDocumentRequirementResource::collection($requirements));
    }

    public function store(CreateStageDocumentRequirementRequest $request, WorkflowStage $stage): JsonResponse
    {
        $stage->load('workflow');
        $store = $stage->workflow->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage document requirements.');
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

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage document requirements.');
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

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage document requirements.');
        }

        $this->requirementService->delete($requirement);

        return ApiResponse::success(message: 'Stage document requirement deleted');
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
