<?php

namespace App\Services\Events;

interface EventHandlerInterface
{
    /**
     * Handle the domain-specific fields from an incoming event envelope.
     *
     * Receives only the `data` portion of the envelope — callers (InboxEventProcessor)
     * are responsible for idempotency, row creation, and status tracking.
     *
     * Implementations must be side-effect-safe for unknown/missing IDs: if the
     * referenced local record does not exist, the handler should return without
     * throwing so the inbox row is still marked processed.
     *
     * @param array<string, mixed> $data envelope['data'] portion
     */
    public function handle(array $data): void;
}
