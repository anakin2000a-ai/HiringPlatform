<?php

namespace App\Models;

use App\Enums\AutomationTrigger;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'hiring_workflow_id',
        'workflow_stage_id',
        'name',
        'trigger',
        'conditions',
        'actions',
        'priority',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions'    => 'array',
            'priority'   => 'integer',
            'is_active'  => 'boolean',
            'trigger'    => AutomationTrigger::class,
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function hiringWorkflow(): BelongsTo
    {
        return $this->belongsTo(HiringWorkflow::class);
    }

    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
