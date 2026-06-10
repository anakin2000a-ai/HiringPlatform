<?php

namespace App\Models;

use Database\Factories\StageDocumentRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageDocumentRequirement extends Model
{
    /** @use HasFactory<StageDocumentRequirementFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_stage_id',
        'document_template_id',
        'is_required',
        'due_days_after_stage_entry',
    ];

    protected function casts(): array
    {
        return [
            'is_required'               => 'boolean',
            'due_days_after_stage_entry' => 'integer',
        ];
    }

    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class);
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function applicantDocuments(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }
}
