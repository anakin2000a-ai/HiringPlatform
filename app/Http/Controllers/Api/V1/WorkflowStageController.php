<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\CreateWorkflowStageRequest;
use App\Http\Requests\Workflows\UpdateWorkflowStageRequest;
use App\Http\Resources\WorkflowStageResource;
use App\Http\Responses\ApiResponse;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\WorkflowStage;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Workflows\WorkflowStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowStageController extends Controller
{
    public function __construct(
        private readonly WorkflowStageService $stageService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    
    public function store(CreateWorkflowStageRequest $request, Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $stage = $this->stageService->create($workflow, $request->validated());

        return ApiResponse::created(new WorkflowStageResource($stage), 'Stage created');
    }

    public function update(UpdateWorkflowStageRequest $request, Store $store, HiringWorkflow $workflow, WorkflowStage $stage): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->stageBelongsToWorkflow($stage, $workflow)) {
            return ApiResponse::notFound('Stage not found');
        }

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $stage = $this->stageService->update($stage, $request->validated());

        return ApiResponse::success(new WorkflowStageResource($stage));
    }

    public function destroy(Request $request, Store $store, HiringWorkflow $workflow, WorkflowStage $stage): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->stageBelongsToWorkflow($stage, $workflow)) {
            return ApiResponse::notFound('Stage not found');
        }

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $this->stageService->delete($stage);

        return ApiResponse::success(message: 'Stage deleted');
    }

    private function workflowBelongsToStore(HiringWorkflow $workflow, Store $store): bool
    {
        return $workflow->store_id === $store->id;
    }

    private function stageBelongsToWorkflow(WorkflowStage $stage, HiringWorkflow $workflow): bool
    {
        return $stage->hiring_workflow_id === $workflow->id;
    }

    private function canManageWorkflows(Request $request, Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }
}
