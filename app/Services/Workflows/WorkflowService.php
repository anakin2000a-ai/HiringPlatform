<?php

namespace App\Services\Workflows;

use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function create(Store $store, array $data, User $createdBy): HiringWorkflow
    {
        $stagesData = $data['stages'] ?? [];
        $version = $data['version'] ?? 1;

        $this->ensureUniqueName($store->id, $data['name'], $version);

        return DB::transaction(function () use ($store, $data, $createdBy, $stagesData, $version): HiringWorkflow {
            $workflow = HiringWorkflow::create([
                'store_id' => $store->id,
                'name' => $data['name'],
                'version' => $version,
                'status' => $data['status'] ?? 'draft',
                'parent_workflow_id' => $data['parent_workflow_id'] ?? null,
                'created_by' => $createdBy->id,
            ]);

            foreach ($stagesData as $stageData) {
                WorkflowStage::create([
                    'hiring_workflow_id' => $workflow->id,
                    'name' => $stageData['name'],
                    'stage_type' => $stageData['stage_type'],
                    'position' => $stageData['position'],
                    'is_initial' => $stageData['is_initial'] ?? false,
                    'is_terminal' => $stageData['is_terminal'] ?? false,
                    'auto_advance_enabled' => $stageData['auto_advance_enabled'] ?? false,
                    'configuration' => $stageData['configuration'] ?? null,
                ]);
            }

            return $workflow->load('stages');
        });
    }

    public function update(HiringWorkflow $workflow, array $data): HiringWorkflow
    {
        $updates = [];

        if (array_key_exists('name', $data)) {
            $newName = $data['name'];
            if ($newName !== $workflow->name) {
                $this->ensureUniqueName($workflow->store_id, $newName, $workflow->version, $workflow->id);
            }
            $updates['name'] = $newName;
        }

        if (array_key_exists('status', $data)) {
            $newStatus = $data['status'];

            if ($workflow->status === 'archived' && $newStatus !== 'archived') {
                throw ValidationException::withMessages([
                    'status' => ['Cannot change the status of an archived workflow.'],
                ]);
            }

            $updates['status'] = $newStatus;

            if ($newStatus === 'active' && $workflow->published_at === null) {
                $updates['published_at'] = now();
            }

            if ($newStatus === 'archived' && $workflow->archived_at === null) {
                $updates['archived_at'] = now();
            }
        }

        $workflow->update($updates);

        return $workflow->fresh();
    }

    public function delete(HiringWorkflow $workflow): void
    {
        $workflow->delete();
    }

    private function ensureUniqueName(int $storeId, string $name, int $version, ?int $excludeId = null): void
    {
        $query = HiringWorkflow::where('store_id', $storeId)
            ->where('name', $name)
            ->where('version', $version);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ["A workflow named '{$name}' with version {$version} already exists for this store."],
            ]);
        }
    }
}
