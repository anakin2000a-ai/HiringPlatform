<?php

namespace App\Models;

use App\Enums\TransitionType;
use Database\Factories\ApplicationStageTransitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStageTransition extends Model
{
    /** @use HasFactory<ApplicationStageTransitionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'from_stage_id',
        'to_stage_id',
        'changed_by',
        'transition_type',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'        => 'array',
            'created_at'      => 'datetime',
            'transition_type' => TransitionType::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
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
