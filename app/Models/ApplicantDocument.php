<?php

namespace App\Models;

use Database\Factories\ApplicantDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantDocument extends Model
{
    /** @use HasFactory<ApplicantDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'application_id',
        'workflow_stage_id',
        'stage_document_requirement_id',
        'document_template_id',
        'status',
        'file_path',
        'external_signature_id',
        'submitted_at',
        'signed_at',
        'approved_by',
        'approved_at',
        'rejected_at',
        'rejected_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'signed_at'    => 'datetime',
            'approved_at'  => 'datetime',
            'rejected_at'  => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class);
    }

    public function stageDocumentRequirement(): BelongsTo
    {
        return $this->belongsTo(StageDocumentRequirement::class);
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
