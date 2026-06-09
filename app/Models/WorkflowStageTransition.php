<?php

namespace App\Models;

use Database\Factories\WorkflowStageTransitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStageTransition extends Model
{
    /** @use HasFactory<WorkflowStageTransitionFactory> */
    use HasFactory;

    protected $fillable = [
        'hiring_workflow_id',
        'from_stage_id',
        'to_stage_id',
        'name',
        'is_manual_allowed',
        'is_automatic_allowed',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'is_manual_allowed' => 'boolean',
            'is_automatic_allowed' => 'boolean',
            'conditions' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(HiringWorkflow::class, 'hiring_workflow_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'to_stage_id');
    }
}
