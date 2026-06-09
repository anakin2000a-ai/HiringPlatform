<?php

namespace App\Models;

use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'job_opening_id',
        'current_stage_id',
        'status',
        'score',
        'applied_at',
        'rejected_at',
        'hired_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'rejected_at' => 'datetime',
            'hired_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function answers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApplicantAnswer::class);
    }

    public function stageTransitions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApplicationStageTransition::class)->orderBy('transitioned_at');
    }

    public function activities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkflowActivity::class)->orderByDesc('created_at');
    }
}
