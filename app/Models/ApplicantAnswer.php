<?php

namespace App\Models;

use Database\Factories\ApplicantAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantAnswer extends Model
{
    /** @use HasFactory<ApplicantAnswerFactory> */
    use HasFactory;

    protected $fillable = [
        'application_id',
        'questionnaire_template_id',
        'questionnaire_question_id',
        'answer',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'answer'      => 'json',
            'answered_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function questionnaireTemplate(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplate::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireQuestion::class, 'questionnaire_question_id');
    }
}
