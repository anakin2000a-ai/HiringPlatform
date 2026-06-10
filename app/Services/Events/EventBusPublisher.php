<?php

namespace App\Services\Events;

interface EventBusPublisher
{
    /**
     * Publish an event to the given subject on the event bus.
     *
     * Implementations must be idempotent-safe: callers are responsible for
     * deduplication at the outbox level; the publisher itself just fires-and-forgets.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(string $subject, array $payload): void;
}
