<?php

namespace App\Models;

use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'document_type',
        'requires_signature',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_signature' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stageRequirements(): HasMany
    {
        return $this->hasMany(StageDocumentRequirement::class);
    }
}
