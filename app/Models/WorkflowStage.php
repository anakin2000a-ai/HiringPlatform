<?php

namespace App\Models;

use Database\Factories\WorkflowStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStage extends Model
{
    /** @use HasFactory<WorkflowStageFactory> */
    use HasFactory;

    protected $fillable = [
        'hiring_workflow_id',
        'name',
        'stage_type',
        'position',
        'is_initial',
        'is_terminal',
        'auto_advance_enabled',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'is_terminal' => 'boolean',
            'auto_advance_enabled' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(HiringWorkflow::class, 'hiring_workflow_id');
    }

    public function currentApplications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Application::class, 'current_stage_id');
    }

    public function questionnaireAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StageQuestionnaireAssignment::class);
    }

    public function documentRequirements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StageDocumentRequirement::class);
    }
}
