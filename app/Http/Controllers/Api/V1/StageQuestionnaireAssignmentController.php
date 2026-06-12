<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Questionnaires\AssignQuestionnaireToStageRequest;
use App\Http\Resources\StageQuestionnaireAssignmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\WorkflowStage;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Questionnaires\StageQuestionnaireAssignmentService;
use Illuminate\Http\JsonResponse;

class StageQuestionnaireAssignmentController extends Controller
{
    public function __construct(
        private readonly StageQuestionnaireAssignmentService $assignmentService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function store(
        AssignQuestionnaireToStageRequest $request,
        Store $store,
        HiringWorkflow $workflow,
        WorkflowStage $stage,
    ): JsonResponse {
        if (! $this->workflowBelongsToStore($workflow, $store)) {
            return ApiResponse::notFound('Workflow not found');
        }

        if (! $this->stageBelongsToWorkflow($stage, $workflow)) {
            return ApiResponse::notFound('Stage not found');
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can assign questionnaires to stages.');
        }

        $assignment = $this->assignmentService->assign($store, $stage, $request->validated());

        return ApiResponse::created(
            new StageQuestionnaireAssignmentResource($assignment->load('questionnaireTemplate')),
            'Questionnaire assigned to stage'
        );
    }

    private function workflowBelongsToStore(HiringWorkflow $workflow, Store $store): bool
    {
        return $workflow->store_id === $store->id;
    }

    private function stageBelongsToWorkflow(WorkflowStage $stage, HiringWorkflow $workflow): bool
    {
        return $stage->hiring_workflow_id === $workflow->id;
    }

    private function canManage(\Illuminate\Http\Request $request, Store $store): bool
    {
        return in_array(
            $this->storeAccessService->getUserRoleAtStore($request->user(), $store),
            ['franchise_admin', 'store_manager'],
            true
        );
    }
}
