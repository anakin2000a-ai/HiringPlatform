<?php

namespace App\Models;

use App\Enums\ActorType;
use Database\Factories\WorkflowActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowActivity extends Model
{
    /** @use HasFactory<WorkflowActivityFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'store_id',
        'workflow_stage_id',
        'actor_type',
        'actor_id',
        'event_type',
        'old_value',
        'new_value',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value'  => 'array',
            'new_value'  => 'array',
            'metadata'   => 'array',
            'created_at' => 'datetime',
            'actor_type' => ActorType::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }
}
