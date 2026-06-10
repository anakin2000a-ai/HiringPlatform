<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\CopyStoreConfigurationRequest;
use App\Http\Resources\ConfigurationCopyLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Store;
use App\Services\Configuration\ConfigurationCopyService;
use Illuminate\Http\JsonResponse;

class ConfigurationCopyController extends Controller
{
    public function __construct(private readonly ConfigurationCopyService $copyService) {}

    public function copy(CopyStoreConfigurationRequest $request, Store $store): JsonResponse
    {
        if (! $this->canManage($request->user())) {
            return ApiResponse::forbidden('Only franchise admins and store managers can copy configuration.');
        }

        $data        = $request->validated();
        $targetStore = Store::findOrFail($data['target_store_id']);

        $log = $this->copyService->copy(
            sourceStore:       $store,
            targetStore:       $targetStore,
            copiedBy:          $request->user(),
            copyWorkflows:     (bool) ($data['copy_workflows'] ?? true),
            copyQuestionnaires:(bool) ($data['copy_questionnaires'] ?? true),
            copyDocuments:     (bool) ($data['copy_documents'] ?? true),
            copyAutomationRules:(bool) ($data['copy_automation_rules'] ?? true),
        );

        return ApiResponse::created(new ConfigurationCopyLogResource($log), 'Configuration copied successfully.');
    }

    private function canManage(\App\Models\User $user): bool
    {
        return in_array($user->role, ['franchise_admin', 'store_manager'], true);
    }
}
