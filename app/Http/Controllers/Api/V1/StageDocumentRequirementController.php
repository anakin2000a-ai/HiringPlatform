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
