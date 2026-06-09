<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\CreateWorkflowRequest;
use App\Http\Requests\Workflows\UpdateWorkflowRequest;
use App\Http\Resources\HiringWorkflowResource;
use App\Http\Responses\ApiResponse;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Services\Workflows\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowService $workflowService) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $workflows = HiringWorkflow::where('store_id', $store->id)
            ->with('stages')
            ->orderBy('name')
            ->orderBy('version')
            ->paginate(20);

        return ApiResponse::success(
            HiringWorkflowResource::collection($workflows)->response()->getData(true)
        );
    }

    public function store(CreateWorkflowRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManageWorkflows($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage workflows.');
        }

        $workflow = $this->workflowService->create($store, $request->validated(), $request->user());

        return ApiResponse::created(new HiringWorkflowResource($workflow), 'Workflow created');
    }

    public function show(Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        return ApiResponse::success(new HiringWorkflowResource($workflow->load('stages')));
    }

    public function update(UpdateWorkflowRequest $request, Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->canManageWorkflows($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage workflows.');
        }

        $workflow = $this->workflowService->update($workflow, $request->validated());

        return ApiResponse::success(new HiringWorkflowResource($workflow->load('stages')));
    }

    public function destroy(Request $request, Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can delete workflows.');
        }

        $this->workflowService->delete($workflow);

        return ApiResponse::success(message: 'Workflow deleted');
    }

    private function workflowBelongsToStore(HiringWorkflow $workflow, Store $store): bool
    {
        return $workflow->store_id === $store->id;
    }

    private function canManageWorkflows(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
