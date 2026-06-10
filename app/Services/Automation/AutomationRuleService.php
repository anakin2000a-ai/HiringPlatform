<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Store;
use App\Models\User;

class AutomationRuleService
{
    public function create(Store $store, array $data, User $creator): AutomationRule
    {
        return AutomationRule::create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $data['hiring_workflow_id'] ?? null,
            'workflow_stage_id'  => $data['workflow_stage_id'] ?? null,
            'name'               => $data['name'],
            'trigger'            => $data['trigger'],
            'conditions'         => $data['conditions'] ?? null,
            'actions'            => $data['actions'],
            'priority'           => $data['priority'] ?? 100,
            'is_active'          => $data['is_active'] ?? true,
            'created_by'         => $creator->id,
        ]);
    }

    public function update(AutomationRule $rule, array $data): AutomationRule
    {
        $allowed = collect($data)->only([
            'hiring_workflow_id',
            'workflow_stage_id',
            'name',
            'trigger',
            'conditions',
            'actions',
            'priority',
            'is_active',
        ])->all();

        $rule->update($allowed);

        return $rule->fresh();
    }

    public function delete(AutomationRule $rule): void
    {
        $rule->delete();
    }
}
