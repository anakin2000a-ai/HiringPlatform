<?php

namespace App\Models;

use Database\Factories\QuestionnaireTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionnaireTemplate extends Model
{
    /** @use HasFactory<QuestionnaireTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'version',
        'status',
        'created_by',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireQuestion::class)->orderBy('position');
    }

    public function stageAssignments(): HasMany
    {
        return $this->hasMany(StageQuestionnaireAssignment::class);
    }
}
