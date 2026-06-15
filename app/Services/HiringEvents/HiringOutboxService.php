<?php

namespace App\Services\HiringEvents;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;

class HiringOutboxService
{
    public function record(string $subject, array $payload): OutboxEvent
    {
        return OutboxEvent::create([
            'event_id'   => $payload['id'],
            'event_type' => $subject,
            'subject'    => $subject,
            'payload'    => $payload,
            'status'     => OutboxEventStatus::Pending,
            'attempts'   => 0,
        ]);
    }
}
