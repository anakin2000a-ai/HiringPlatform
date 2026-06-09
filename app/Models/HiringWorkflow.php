<?php

namespace App\Models;

use Database\Factories\HiringWorkflowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HiringWorkflow extends Model
{
    /** @use HasFactory<HiringWorkflowFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'version',
        'status',
        'parent_workflow_id',
        'created_by',
        'published_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parentWorkflow(): BelongsTo
    {
        return $this->belongsTo(HiringWorkflow::class, 'parent_workflow_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class, 'hiring_workflow_id')->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowStageTransition::class, 'hiring_workflow_id');
    }

    public function initialStage(): HasOne
    {
        return $this->hasOne(WorkflowStage::class, 'hiring_workflow_id')->where('is_initial', true);
    }
}
