<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\MoveApplicationStageRequest;
use App\Http\Requests\Applications\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\WorkflowActivityResource;
use App\Http\Responses\ApiResponse;
use App\Models\Application;
use App\Models\Store;
use App\Models\WorkflowActivity;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\ApplicationStageService;
use App\Services\Automation\AutomationRuleEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly ApplicationStageService $stageService,
        private readonly AutomationRuleEngine $automationEngine,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status'          => ['sometimes', 'string', 'in:pending,active,hired,rejected,withdrawn'],
            'job_opening_id'  => ['sometimes', 'integer'],
            'current_stage_id'=> ['sometimes', 'integer'],
            'search'          => ['sometimes', 'string', 'max:255'],
        ]);

        if ($request->filled('job_opening_id')) {
            $belongs = \App\Models\JobOpening::where('id', $request->integer('job_opening_id'))
                ->where('store_id', $store->id)
                ->exists();
            if (! $belongs) {
                return ApiResponse::validationError(['job_opening_id' => ['The selected job opening does not belong to this store.']]);
            }
        }

        if ($request->filled('current_stage_id')) {
            $belongs = \App\Models\WorkflowStage::where('id', $request->integer('current_stage_id'))
                ->whereHas('workflow', fn ($q) => $q->where('store_id', $store->id))
                ->exists();
            if (! $belongs) {
                return ApiResponse::validationError(['current_stage_id' => ['The selected stage does not belong to this store.']]);
            }
        }

        $query = Application::whereHas('jobOpening', fn ($q) => $q->where('store_id', $store->id))
            ->with(['applicant', 'currentStage']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('job_opening_id')) {
            $query->where('job_opening_id', $request->integer('job_opening_id'));
        }
        if ($request->filled('current_stage_id')) {
            $query->where('current_stage_id', $request->integer('current_stage_id'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->whereHas('applicant', fn ($q) => $q
                ->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('email', 'like', $term)
            );
        }

        $applications = $query->orderByDesc('applied_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            ApplicationResource::collection($applications)->response()->getData(true)
        );
    }

    public function show(Store $store, Application $application): JsonResponse
    {
        if (! $this->applicationBelongsToStore($application, $store)) {
            return ApiResponse::notFound('Application not found');
        }

        return ApiResponse::success(
            new ApplicationResource($application->load(['applicant', 'jobOpening', 'currentStage']))
        );
    }

    public function update(UpdateApplicationRequest $request, Store $store, Application $application): JsonResponse
    {
        if (! $this->applicationBelongsToStore($application, $store)) {
            return ApiResponse::notFound('Application not found');
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $application = $this->applicationService->update($application, $request->validated());

        return ApiResponse::success(
            new ApplicationResource($application->load(['applicant', 'jobOpening', 'currentStage']))
        );
    }

    public function moveStage(MoveApplicationStageRequest $request, Store $store, Application $application): JsonResponse
    {
        if (! $this->applicationBelongsToStore($application, $store)) {
            return ApiResponse::notFound('Application not found');
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $application = $this->stageService->move(
            $application,
            $request->integer('to_stage_id'),
            $request->input('reason'),
            $request->user(),
        );

        $this->automationEngine->evaluate('stage_entered', $application);

        return ApiResponse::success(
            new ApplicationResource($application)
        );
    }

    public function activities(Request $request, Store $store, Application $application): JsonResponse
    {
        if (! $this->applicationBelongsToStore($application, $store)) {
            return ApiResponse::notFound('Application not found');
        }

        $request->validate([
            'per_page'          => ['sometimes', 'integer', 'min:1', 'max:100'],
            'event_type'        => ['sometimes', 'string'],
            'actor_type'        => ['sometimes', 'string'],
            'workflow_stage_id' => ['sometimes', 'integer'],
        ]);

        $query = WorkflowActivity::where('application_id', $application->id);

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }
        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->input('actor_type'));
        }
        if ($request->filled('workflow_stage_id')) {
            $query->where('workflow_stage_id', $request->integer('workflow_stage_id'));
        }

        $activities = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            WorkflowActivityResource::collection($activities)->response()->getData(true)
        );
    }

    private function applicationBelongsToStore(Application $application, Store $store): bool
    {
        return $application->jobOpening->store_id === $store->id;
    }

    private function canManage(Request $request, Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }
}
