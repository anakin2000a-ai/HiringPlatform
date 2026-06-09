<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\ApplyToJobOpeningRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\JobOpening;
use App\Models\Store;
use App\Services\Applications\CreateApplicationService;
use Illuminate\Http\JsonResponse;

class JobOpeningApplicationController extends Controller
{
    public function __construct(private readonly CreateApplicationService $createApplicationService) {}

    public function apply(ApplyToJobOpeningRequest $request, Store $store, JobOpening $jobOpening): JsonResponse
    {
        if ($jobOpening->store_id !== $store->id) {
            return ApiResponse::notFound('Job opening not found');
        }

        if (! $jobOpening->isPublished()) {
            return ApiResponse::error('This job opening is not accepting applications.', 422);
        }

        $application = $this->createApplicationService->create($jobOpening, $request->validated());

        return ApiResponse::created(new ApplicationResource($application), 'Application submitted successfully');
    }
}
