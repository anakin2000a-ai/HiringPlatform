<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\CreateWorkflowStageTransitionRequest;
use App\Http\Requests\Workflows\UpdateWorkflowStageTransitionRequest;
use App\Http\Resources\WorkflowStageTransitionResource;
use App\Http\Responses\ApiResponse;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\WorkflowStageTransition;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Workflows\WorkflowTransitionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowStageTransitionController extends Controller
{
    public function __construct(
        private readonly WorkflowTransitionValidator $transitionValidator,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(\Illuminate\Http\Request $request, Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        $request->validate([
            'per_page'            => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from_stage_id'       => ['sometimes', 'integer'],
            'to_stage_id'         => ['sometimes', 'integer'],
            'is_manual_allowed'   => ['sometimes', 'boolean'],
            'is_automatic_allowed'=> ['sometimes', 'boolean'],
        ]);

        $query = $workflow->transitions();

        if ($request->filled('from_stage_id')) {
            $query->where('from_stage_id', $request->integer('from_stage_id'));
        }
        if ($request->filled('to_stage_id')) {
            $query->where('to_stage_id', $request->integer('to_stage_id'));
        }
        if ($request->has('is_manual_allowed')) {
            $query->where('is_manual_allowed', $request->boolean('is_manual_allowed'));
        }
        if ($request->has('is_automatic_allowed')) {
            $query->where('is_automatic_allowed', $request->boolean('is_automatic_allowed'));
        }

        $transitions = $query->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            WorkflowStageTransitionResource::collection($transitions)->response()->getData(true)
        );
    }

    public function store(CreateWorkflowStageTransitionRequest $request, Store $store, HiringWorkflow $workflow): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage workflow transitions.');
        }

        $validated = $request->validated();
        $fromStageId = $validated['from_stage_id'] ?? null;

        $this->transitionValidator->validate($workflow, $fromStageId, $validated['to_stage_id']);

        $transition = WorkflowStageTransition::create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $validated['to_stage_id'],
            'name' => $validated['name'] ?? null,
            'is_manual_allowed' => $validated['is_manual_allowed'] ?? true,
            'is_automatic_allowed' => $validated['is_automatic_allowed'] ?? true,
            'conditions' => $validated['conditions'] ?? null,
        ]);

        return ApiResponse::created(new WorkflowStageTransitionResource($transition), 'Transition created');
    }

    public function update(UpdateWorkflowStageTransitionRequest $request, Store $store, HiringWorkflow $workflow, WorkflowStageTransition $transition): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->transitionBelongsToWorkflow($transition, $workflow)) {
            return ApiResponse::notFound('Transition not found');
        }

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage workflow transitions.');
        }

        $validated = $request->validated();

        $fromStageId = array_key_exists('from_stage_id', $validated)
            ? $validated['from_stage_id']
            : $transition->from_stage_id;

        $toStageId = $validated['to_stage_id'] ?? $transition->to_stage_id;

        $this->transitionValidator->validate($workflow, $fromStageId, $toStageId, $transition->id);

        $transition->update($validated);

        return ApiResponse::success(new WorkflowStageTransitionResource($transition->fresh()));
    }

    public function destroy(Request $request, Store $store, HiringWorkflow $workflow, WorkflowStageTransition $transition): JsonResponse
    {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->transitionBelongsToWorkflow($transition, $workflow)) {
            return ApiResponse::notFound('Transition not found');
        }

        if (! $this->canManageWorkflows($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage workflow transitions.');
        }

        $transition->delete();

        return ApiResponse::success(message: 'Transition deleted');
    }

    private function workflowBelongsToStore(HiringWorkflow $workflow, Store $store): bool
    {
        return $workflow->store_id === $store->id;
    }

    private function transitionBelongsToWorkflow(WorkflowStageTransition $transition, HiringWorkflow $workflow): bool
    {
        return $transition->hiring_workflow_id === $workflow->id;
    }

    private function canManageWorkflows(Request $request, Store $store): bool
    {
        return in_array(
            $this->storeAccessService->getUserRoleAtStore($request->user(), $store),
            ['franchise_admin', 'store_manager'],
            true
        );
    }
}
