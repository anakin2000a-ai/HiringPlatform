<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\CopyStoreConfigurationRequest;
use App\Http\Resources\ConfigurationCopyLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Store;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Configuration\ConfigurationCopyService;
use Illuminate\Http\JsonResponse;

class ConfigurationCopyController extends Controller
{
    public function __construct(
        private readonly ConfigurationCopyService $copyService,
        private readonly StoreAccessService $storeAccessService,
    ) {}

    public function copy(CopyStoreConfigurationRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request->user(), $store)) {
            return ApiResponse::forbidden('You do not have access to this store.');
        }

        $data        = $request->validated();
        $targetStore = Store::findOrFail($data['target_store_id']);

        $log = $this->copyService->copy(
            sourceStore:        $store,
            targetStore:        $targetStore,
            copiedBy:           $request->user(),
            copyWorkflows:      (bool) ($data['copy_workflows'] ?? true),
            copyQuestionnaires: (bool) ($data['copy_questionnaires'] ?? true),
            copyDocuments:      (bool) ($data['copy_documents'] ?? true),
            copyAutomationRules:(bool) ($data['copy_automation_rules'] ?? true),
        );

        return ApiResponse::created(new ConfigurationCopyLogResource($log), 'Configuration copied successfully.');
    }

    private function canManage(\App\Models\User $user, Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($user, $store);
    }
}
