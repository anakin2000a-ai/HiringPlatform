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
use App\Services\Applications\ApplicationService;
use App\Services\Applications\ApplicationStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly ApplicationStageService $stageService,
    ) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $query = Application::whereHas('jobOpening', fn ($q) => $q->where('store_id', $store->id))
            ->with(['applicant', 'jobOpening', 'currentStage'])
            ->orderByDesc('applied_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('job_opening_id')) {
            $query->where('job_opening_id', $request->input('job_opening_id'));
        }

        $applications = $query->paginate(20);

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

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can update applications.');
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

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can move application stages.');
        }

        $application = $this->stageService->move(
            $application,
            $request->integer('to_stage_id'),
            $request->input('reason'),
            $request->user(),
        );

        return ApiResponse::success(
            new ApplicationResource($application)
        );
    }

    public function activities(Store $store, Application $application): JsonResponse
    {
        if (! $this->applicationBelongsToStore($application, $store)) {
            return ApiResponse::notFound('Application not found');
        }

        $activities = WorkflowActivity::where('application_id', $application->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::success(
            WorkflowActivityResource::collection($activities)->response()->getData(true)
        );
    }

    private function applicationBelongsToStore(Application $application, Store $store): bool
    {
        return $application->jobOpening->store_id === $store->id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
