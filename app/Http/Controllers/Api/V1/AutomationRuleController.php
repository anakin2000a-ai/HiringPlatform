<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\CreateAutomationRuleRequest;
use App\Http\Requests\Automation\UpdateAutomationRuleRequest;
use App\Http\Resources\AutomationRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\AutomationRule;
use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationRuleController extends Controller
{
    public function __construct(
        private readonly AutomationRuleService $ruleService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(\Illuminate\Http\Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'per_page'          => ['sometimes', 'integer', 'min:1', 'max:100'],
            'trigger'           => ['sometimes', 'string'],
            'is_active'         => ['sometimes', 'boolean'],
            'hiring_workflow_id'=> ['sometimes', 'integer'],
            'workflow_stage_id' => ['sometimes', 'integer'],
            'search'            => ['sometimes', 'string', 'max:255'],
        ]);

        $query = AutomationRule::where('store_id', $store->id);

        if ($request->filled('trigger')) {
            $query->where('trigger', $request->input('trigger'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('hiring_workflow_id')) {
            $query->where('hiring_workflow_id', $request->integer('hiring_workflow_id'));
        }
        if ($request->filled('workflow_stage_id')) {
            $query->where('workflow_stage_id', $request->integer('workflow_stage_id'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        $rules = $query->orderBy('priority')->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            AutomationRuleResource::collection($rules)->response()->getData(true)
        );
    }

    public function store(CreateAutomationRuleRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage automation rules.');
        }

        $data = $request->validated();

        if (! $this->workflowBelongsToStore($data['hiring_workflow_id'] ?? null, $store)) {
            return ApiResponse::validationError(['hiring_workflow_id' => ['The workflow does not belong to this store.']]);
        }

        if (! $this->stageBelongsToWorkflowStore($data['workflow_stage_id'] ?? null, $store)) {
            return ApiResponse::validationError(['workflow_stage_id' => ['The stage does not belong to this store.']]);
        }

        $rule = $this->ruleService->create($store, $data, $request->user());

        return ApiResponse::created(new AutomationRuleResource($rule), 'Automation rule created');
    }

    public function show(Request $request, AutomationRule $automationRule): JsonResponse
    {
        if (! $this->ruleAccessibleToUser($automationRule, $request)) {
            return ApiResponse::forbidden();
        }

        return ApiResponse::success(new AutomationRuleResource($automationRule));
    }

    public function update(UpdateAutomationRuleRequest $request, AutomationRule $automationRule): JsonResponse
    {
        $store = $automationRule->store ?? $automationRule->load('store')->store;

        if (! $this->ruleAccessibleToUser($automationRule, $request)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage automation rules.');
        }

        $data = $request->validated();

        if (array_key_exists('hiring_workflow_id', $data)) {
            if (! $this->workflowBelongsToStore($data['hiring_workflow_id'], $store)) {
                return ApiResponse::validationError(['hiring_workflow_id' => ['The workflow does not belong to this store.']]);
            }
        }

        if (array_key_exists('workflow_stage_id', $data)) {
            if (! $this->stageBelongsToWorkflowStore($data['workflow_stage_id'], $store)) {
                return ApiResponse::validationError(['workflow_stage_id' => ['The stage does not belong to this store.']]);
            }
        }

        $rule = $this->ruleService->update($automationRule, $data);

        return ApiResponse::success(new AutomationRuleResource($rule));
    }

    public function destroy(Request $request, AutomationRule $automationRule): JsonResponse
    {
        $store = $automationRule->store ?? $automationRule->load('store')->store;

        if (! $this->ruleAccessibleToUser($automationRule, $request)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage automation rules.');
        }

        $this->ruleService->delete($automationRule);

        return ApiResponse::success(message: 'Automation rule deleted');
    }

    private function ruleAccessibleToUser(AutomationRule $rule, Request $request): bool
    {
        $store = $rule->store ?? $rule->load('store')->store;

        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }

    private function workflowBelongsToStore(?int $workflowId, Store $store): bool
    {
        if ($workflowId === null) {
            return true;
        }

        return \App\Models\HiringWorkflow::where('id', $workflowId)
            ->where('store_id', $store->id)
            ->exists();
    }

    private function stageBelongsToWorkflowStore(?int $stageId, Store $store): bool
    {
        if ($stageId === null) {
            return true;
        }

        return \App\Models\WorkflowStage::whereHas(
            'workflow',
            fn ($q) => $q->where('store_id', $store->id)
        )->where('id', $stageId)->exists();
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
