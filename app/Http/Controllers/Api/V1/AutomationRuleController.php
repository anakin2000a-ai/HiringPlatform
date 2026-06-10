<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\CreateAutomationRuleRequest;
use App\Http\Requests\Automation\UpdateAutomationRuleRequest;
use App\Http\Resources\AutomationRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\AutomationRule;
use App\Models\Store;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationRuleController extends Controller
{
    public function __construct(private readonly AutomationRuleService $ruleService) {}

    public function index(Store $store): JsonResponse
    {
        $rules = AutomationRule::where('store_id', $store->id)
            ->orderBy('priority')
            ->orderBy('id')
            ->paginate(20);

        return ApiResponse::success(
            AutomationRuleResource::collection($rules)->response()->getData(true)
        );
    }

    public function store(CreateAutomationRuleRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request)) {
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
        if (! $this->ruleAccessibleToUser($automationRule, $request)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage automation rules.');
        }

        $data = $request->validated();

        if (array_key_exists('hiring_workflow_id', $data)) {
            $store = $automationRule->store;
            if (! $this->workflowBelongsToStore($data['hiring_workflow_id'], $store)) {
                return ApiResponse::validationError(['hiring_workflow_id' => ['The workflow does not belong to this store.']]);
            }
        }

        if (array_key_exists('workflow_stage_id', $data)) {
            $store = $automationRule->store ?? $automationRule->load('store')->store;
            if (! $this->stageBelongsToWorkflowStore($data['workflow_stage_id'], $store)) {
                return ApiResponse::validationError(['workflow_stage_id' => ['The stage does not belong to this store.']]);
            }
        }

        $rule = $this->ruleService->update($automationRule, $data);

        return ApiResponse::success(new AutomationRuleResource($rule));
    }

    public function destroy(Request $request, AutomationRule $automationRule): JsonResponse
    {
        if (! $this->ruleAccessibleToUser($automationRule, $request)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage automation rules.');
        }

        $this->ruleService->delete($automationRule);

        return ApiResponse::success(message: 'Automation rule deleted');
    }

    private function ruleAccessibleToUser(AutomationRule $rule, Request $request): bool
    {
        $user  = $request->user();
        $store = $rule->store ?? $rule->load('store')->store;

        if ($user->hasFranchiseScope()) {
            return $user->franchise_account_id === $store->franchise_account_id;
        }

        return $user->storeAccesses()->where('store_id', $store->id)->exists();
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

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
