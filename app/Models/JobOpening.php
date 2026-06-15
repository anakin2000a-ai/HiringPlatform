<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\JobOpeningStatus;
use Database\Factories\JobOpeningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOpening extends Model
{
    /** @use HasFactory<JobOpeningFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'hiring_workflow_id',
        'title',
        'description',
        'employment_type',
        'openings_count',
        'status',
        'published_at',
        'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at'    => 'datetime',
            'closed_at'       => 'datetime',
            'status'          => JobOpeningStatus::class,
            'employment_type' => EmploymentType::class,
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function isDraft(): bool
    {
        return $this->status === JobOpeningStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === JobOpeningStatus::Published;
    }

    public function isClosed(): bool
    {
        return $this->status === JobOpeningStatus::Closed;
    }
}
