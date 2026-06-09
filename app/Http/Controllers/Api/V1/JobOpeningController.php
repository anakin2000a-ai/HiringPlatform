<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JobOpenings\CreateJobOpeningRequest;
use App\Http\Requests\JobOpenings\UpdateJobOpeningRequest;
use App\Http\Resources\JobOpeningResource;
use App\Http\Responses\ApiResponse;
use App\Models\JobOpening;
use App\Models\Store;
use App\Services\JobOpenings\JobOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobOpeningController extends Controller
{
    public function __construct(private readonly JobOpeningService $jobOpeningService) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $query = JobOpening::where('store_id', $store->id)
            ->with('hiringWorkflow')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $jobOpenings = $query->paginate(20);

        return ApiResponse::success(
            JobOpeningResource::collection($jobOpenings)->response()->getData(true)
        );
    }

    public function store(CreateJobOpeningRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can create job openings.');
        }

        $jobOpening = $this->jobOpeningService->create($store, $request->validated(), $request->user());

        return ApiResponse::created(new JobOpeningResource($jobOpening), 'Job opening created');
    }

    public function show(Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        return ApiResponse::success(new JobOpeningResource($jobOpening->load('hiringWorkflow')));
    }

    public function update(UpdateJobOpeningRequest $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can update job openings.');
        }

        $jobOpening = $this->jobOpeningService->update($jobOpening, $request->validated());

        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    public function destroy(Request $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $request->user()->isFranchiseAdmin()) {
            return ApiResponse::forbidden('Only franchise admins can delete job openings.');
        }

        $this->jobOpeningService->delete($jobOpening);

        return ApiResponse::success(message: 'Job opening deleted');
    }

    public function publish(Request $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can publish job openings.');
        }

        $jobOpening = $this->jobOpeningService->publish($jobOpening);

        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    public function close(Request $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can close job openings.');
        }

        $jobOpening = $this->jobOpeningService->close($jobOpening);

        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    private function jobOpeningBelongsToStore(JobOpening $jobOpening, Store $store): bool
    {
        return $jobOpening->store_id === $store->id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
