<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\Log;

class LogEventBusPublisher implements EventBusPublisher
{
    public function publish(string $subject, array $payload): void
    {
        Log::info("EventBus publish: {$subject}", ['payload' => $payload]);
    }
}
