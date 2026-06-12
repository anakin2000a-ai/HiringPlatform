<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JobOpenings\CreateJobOpeningRequest;
use App\Http\Requests\JobOpenings\UpdateJobOpeningRequest;
use App\Http\Resources\JobOpeningResource;
use App\Http\Responses\ApiResponse;
use App\Models\JobOpening;
use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use App\Services\JobOpenings\JobOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobOpeningController extends Controller
{
    public function __construct(
        private readonly JobOpeningService $jobOpeningService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'per_page'          => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status'            => ['sometimes', 'string', 'in:draft,published,closed'],
            'employment_type'   => ['sometimes', 'string', 'max:100'],
            'hiring_workflow_id'=> ['sometimes', 'integer'],
            'search'            => ['sometimes', 'string', 'max:255'],
        ]);

        $query = JobOpening::where('store_id', $store->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->input('employment_type'));
        }
        if ($request->filled('hiring_workflow_id')) {
            $query->where('hiring_workflow_id', $request->integer('hiring_workflow_id'));
        }
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        $jobOpenings = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            JobOpeningResource::collection($jobOpenings)->response()->getData(true)
        );
    }

    public function store(CreateJobOpeningRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
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

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $jobOpening = $this->jobOpeningService->update($jobOpening, $request->validated());

        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    public function destroy(Request $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $this->jobOpeningService->delete($jobOpening);

        return ApiResponse::success(message: 'Job opening deleted');
    }

    public function publish(Request $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $jobOpening = $this->jobOpeningService->publish($jobOpening);

        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    public function close(Request $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if (! $this->jobOpeningBelongsToStore($jobOpening, $store)) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $jobOpening = $this->jobOpeningService->close($jobOpening);

        return ApiResponse::success(new JobOpeningResource($jobOpening));
    }

    private function jobOpeningBelongsToStore(JobOpening $jobOpening, Store $store): bool
    {
        return $jobOpening->store_id === $store->id;
    }

    private function canManage(Request $request, Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }
}
