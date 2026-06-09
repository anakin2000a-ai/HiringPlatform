<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Questionnaires\SubmitApplicantAnswersRequest;
use App\Http\Resources\ApplicantAnswerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Application;
use App\Models\Store;
use App\Services\Questionnaires\ApplicantAnswerService;
use Illuminate\Http\JsonResponse;

class ApplicantAnswerController extends Controller
{
    public function __construct(private readonly ApplicantAnswerService $answerService) {}

    public function store(SubmitApplicantAnswersRequest $request, Store $store, Application $application): JsonResponse
    {
        if (! $this->applicationBelongsToStore($application, $store)) {
            return ApiResponse::notFound('Application not found');
        }

        $answers = $this->answerService->submit(
            $application,
            $store,
            $request->validated(),
            $request->user(),
        );

        return ApiResponse::created(
            ApplicantAnswerResource::collection($answers),
            'Answers submitted'
        );
    }

    private function applicationBelongsToStore(Application $application, Store $store): bool
    {
        return $application->jobOpening->store_id === $store->id;
    }
}
