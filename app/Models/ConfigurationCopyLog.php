<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationCopyLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source_store_id',
        'target_store_id',
        'copied_by',
        'copied_items',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'copied_items' => 'array',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
        ];
    }

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function targetStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'target_store_id');
    }

    public function copiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'copied_by');
    }
}
