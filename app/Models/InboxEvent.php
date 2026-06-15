<?php

namespace App\Models;

use App\Enums\InboxEventStatus;
use Illuminate\Database\Eloquent\Model;

class InboxEvent extends Model
{
    protected $fillable = [
        'event_id',
        'subject',
        'event_type',
        'payload',
        'status',
        'attempts',
        'processed_at',
        'failed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
            'failed_at'    => 'datetime',
            'status'       => InboxEventStatus::class,
        ];
    }
}
