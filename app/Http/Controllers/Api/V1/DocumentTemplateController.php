<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CreateDocumentTemplateRequest;
use App\Http\Requests\Documents\UpdateDocumentTemplateRequest;
use App\Http\Resources\DocumentTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\DocumentTemplate;
use App\Models\Store;
use App\Services\Documents\DocumentTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTemplateController extends Controller
{
    public function __construct(private readonly DocumentTemplateService $templateService) {}

    public function index(Store $store): JsonResponse
    {
        $templates = DocumentTemplate::where('store_id', $store->id)
            ->orderBy('name')
            ->paginate(20);

        return ApiResponse::success(
            DocumentTemplateResource::collection($templates)->response()->getData(true)
        );
    }

    public function store(CreateDocumentTemplateRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage document templates.');
        }

        $template = $this->templateService->create($store, $request->validated(), $request->user());

        return ApiResponse::created(new DocumentTemplateResource($template), 'Document template created');
    }

    public function show(Store $store, DocumentTemplate $documentTemplate): JsonResponse
    {
        if (! $this->templateBelongsToStore($documentTemplate, $store)) {
            return ApiResponse::notFound('Document template not found');
        }

        return ApiResponse::success(new DocumentTemplateResource($documentTemplate));
    }

    public function update(UpdateDocumentTemplateRequest $request, Store $store, DocumentTemplate $documentTemplate): JsonResponse
    {
        if (! $this->templateBelongsToStore($documentTemplate, $store)) {
            return ApiResponse::notFound('Document template not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage document templates.');
        }

        $template = $this->templateService->update($documentTemplate, $request->validated());

        return ApiResponse::success(new DocumentTemplateResource($template));
    }

    public function destroy(Request $request, Store $store, DocumentTemplate $documentTemplate): JsonResponse
    {
        if (! $this->templateBelongsToStore($documentTemplate, $store)) {
            return ApiResponse::notFound('Document template not found');
        }

        if (! $this->canManage($request)) {
            return ApiResponse::forbidden('Only franchise admins and store managers can manage document templates.');
        }

        $this->templateService->delete($documentTemplate);

        return ApiResponse::success(message: 'Document template deleted');
    }

    private function templateBelongsToStore(DocumentTemplate $template, Store $store): bool
    {
        return $template->store_id === $store->id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['franchise_admin', 'store_manager'], true);
    }
}
