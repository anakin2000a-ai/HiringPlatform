<?php

namespace App\Models;

use App\Enums\QuestionType;
use Database\Factories\QuestionnaireQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionnaireQuestion extends Model
{
    /** @use HasFactory<QuestionnaireQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'questionnaire_template_id',
        'question_key',
        'label',
        'type',
        'options',
        'validation_rules',
        'visibility_rules',
        'is_required',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'options'          => 'array',
            'validation_rules' => 'array',
            'visibility_rules' => 'array',
            'is_required'      => 'boolean',
            'type'             => QuestionType::class,
        ];
    }

    public function questionnaireTemplate(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ApplicantAnswer::class);
    }
}
