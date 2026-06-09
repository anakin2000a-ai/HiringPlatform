<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'subject',
        'payload',
        'status',
        'attempts',
        'published_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
