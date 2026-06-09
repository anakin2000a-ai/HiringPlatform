<?php

namespace App\Models;

use Database\Factories\StageQuestionnaireAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageQuestionnaireAssignment extends Model
{
    /** @use HasFactory<StageQuestionnaireAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_stage_id',
        'questionnaire_template_id',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class);
    }

    public function questionnaireTemplate(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class);
    }
}
