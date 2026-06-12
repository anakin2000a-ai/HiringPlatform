<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\InitiateApplicantDocumentRequest;
use App\Http\Requests\Documents\RejectApplicantDocumentRequest;
use App\Http\Requests\Documents\SignApplicantDocumentRequest;
use App\Http\Requests\Documents\SubmitApplicantDocumentRequest;
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

        $request->validate([
            'per_page'             => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status'               => ['sometimes', 'string', 'in:pending,submitted,signed,approved,rejected'],
            'workflow_stage_id'    => ['sometimes', 'integer'],
            'document_template_id' => ['sometimes', 'integer'],
        ]);

        $query = ApplicantDocument::where('application_id', $application->id)
            ->with('documentTemplate');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('workflow_stage_id')) {
            $query->where('workflow_stage_id', $request->integer('workflow_stage_id'));
        }
        if ($request->filled('document_template_id')) {
            $query->where('document_template_id', $request->integer('document_template_id'));
        }

        $documents = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            ApplicantDocumentResource::collection($documents)->response()->getData(true)
        );
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

   public function submit(SubmitApplicantDocumentRequest $request, ApplicantDocument $document): JsonResponse
    {
        $document->load(['application.jobOpening', 'documentTemplate']);
        $store = $document->application->jobOpening->store;

        if (! $this->storeAccessService->canAccessStore($request->user(), $store)) {
            return ApiResponse::forbidden();
        }

        $document = $this->documentService->submit(
            $document,
            $request->user(),
            $request->file('file')
        );

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

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
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

        if (! $this->canManage($request, $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $document = $this->documentService->reject(
            $document,
            $request->validated('rejected_reason'),
            $request->user()
        );

        return ApiResponse::success(new ApplicantDocumentResource($document), 'Document rejected');
    }

    private function canManage(Request $request, \App\Models\Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($request->user(), $store);
    }
}
