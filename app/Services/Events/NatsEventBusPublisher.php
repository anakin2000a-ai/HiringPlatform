<?php

namespace App\Services\Events;

use RuntimeException;

class NatsEventBusPublisher implements EventBusPublisher
{
    /**
     * Publishes an event to a NATS subject.
     *
     * This is an intentional stub. A real implementation would inject a NATS
     * client (e.g. a php-nats library) via the constructor and call
     * $this->client->publish($subject, json_encode($payload)).
     *
     * The stub is not wired by default so that tests and local development
     * can run without a live NATS server.
     *
     * To activate: bind this class in AppServiceProvider and inject a configured
     * NATS client. Do not call this stub in production without implementing it.
     */
    public function publish(string $subject, array $payload): void
    {
        throw new RuntimeException(
            'NatsEventBusPublisher is not configured. ' .
            'Bind a real NATS client implementation or use LogEventBusPublisher for local development.'
        );
    }
}
