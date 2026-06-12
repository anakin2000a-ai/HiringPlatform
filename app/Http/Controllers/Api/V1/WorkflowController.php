<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\CreateWorkflowRequest;
use App\Http\Requests\Workflows\UpdateWorkflowRequest;
use App\Http\Resources\HiringWorkflowResource;
use App\Http\Responses\ApiResponse;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Workflows\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'string', 'max:255'],
            'status'   => ['sometimes', 'string'],
            'version'  => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = HiringWorkflow::where('store_id', $store->id)->with('stages');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('version')) {
            $query->where('version', $request->integer('version'));
        }

        $workflows = $query->orderBy('name')->orderBy('version')->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            HiringWorkflowResource::collection($workflows)->response()->getData(true)
        );
    }

    public function store(CreateWorkflowRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
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

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $workflow = $this->workflowService->update($workflow, $request->validated());

        return ApiResponse::success(new HiringWorkflowResource($workflow->load('stages')));
    }

    public function destroy(Request $request, Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $this->workflowService->delete($workflow);

        return ApiResponse::success(message: 'Workflow deleted');
    }

    private function workflowBelongsToStore(HiringWorkflow $workflow, Store $store): bool
    {
        return $workflow->store_id === $store->id;
    }

    private function canManageWorkflows(Request $request, Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }
}
