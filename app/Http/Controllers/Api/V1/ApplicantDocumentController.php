<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\InitiateApplicantDocumentRequest;
use App\Http\Requests\Documents\RejectApplicantDocumentRequest;
use App\Http\Requests\Documents\SignApplicantDocumentRequest;
use App\Http\Resources\ApplicantDocumentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Application;
use App\Models\ApplicantDocument;
use App\Models\StageDocumentRequirement;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Documents\ApplicantDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicantDocumentController extends Controller
{
    public function __construct(
        private readonly ApplicantDocumentService $documentService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function index(Request $request, Application $application): JsonResponse
    {
        $application->load('jobOpening');
        $store = $application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        $documents = ApplicantDocument::where('application_id', $application->id)
            ->with('documentTemplate')
            ->get();

        return ApiResponse::success(ApplicantDocumentResource::collection($documents));
    }

    public function store(InitiateApplicantDocumentRequest $request, Application $application): JsonResponse
    {
        $application->load('jobOpening');
        $store = $application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        $requirement = StageDocumentRequirement::with(['workflowStage.workflow', 'documentTemplate'])
            ->findOrFail($request->validated('stage_document_requirement_id'));

        if ($requirement->documentTemplate->store_id !== $store->id) {
            return ApiResponse::forbidden('Document template does not belong to this store.');
        }

        $document = $this->documentService->initiate($application, $requirement);

        return ApiResponse::created(
            new ApplicantDocumentResource($document->load('documentTemplate')),
            'Applicant document initiated'
        );
    }

    public function submit(Request $request, ApplicantDocument $document): JsonResponse
    {
        $document->load(['application.jobOpening', 'documentTemplate']);
        $store = $document->application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        $document = $this->documentService->submit($document, $request->user());

        return ApiResponse::success(new ApplicantDocumentResource($document), 'Document submitted');
    }

    public function sign(SignApplicantDocumentRequest $request, ApplicantDocument $document): JsonResponse
    {
        $document->load(['application.jobOpening', 'documentTemplate']);
        $store = $document->application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        $document = $this->documentService->sign(
            $document,
            $request->validated('external_signature_id'),
            $request->user()
        );

        return ApiResponse::success(new ApplicantDocumentResource($document), 'Document signed');
    }

    public function approve(Request $request, ApplicantDocument $document): JsonResponse
    {
        $document->load(['application.jobOpening', 'documentTemplate']);
        $store = $document->application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can approve documents.');
        }

        $document = $this->documentService->approve($document, $request->user());

        return ApiResponse::success(new ApplicantDocumentResource($document), 'Document approved');
    }

    public function reject(RejectApplicantDocumentRequest $request, ApplicantDocument $document): JsonResponse
    {
        $document->load(['application.jobOpening', 'documentTemplate']);
        $store = $document->application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can reject documents.');
        }

        $document = $this->documentService->reject(
            $document,
            $request->validated('rejected_reason'),
            $request->user()
        );

        return ApiResponse::success(new ApplicantDocumentResource($document), 'Document rejected');
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
