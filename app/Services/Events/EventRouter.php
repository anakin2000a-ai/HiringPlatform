<?php

namespace App\Services\Events;

use App\Models\InboxEvent;
use InvalidArgumentException;
use App\Services\Events\Handlers\NoOpHandler;
use App\Services\Events\Handlers\StoreCreatedHandler;
use App\Services\Events\Handlers\StoreDeletedHandler;
use App\Services\Events\Handlers\StoreUpdatedHandler;
use App\Services\Events\Handlers\UserCreatedHandler;
use App\Services\Events\Handlers\UserDeletedHandler;
use App\Services\Events\Handlers\UserRoleStoreBulkAssignedHandler;
use App\Services\Events\Handlers\UserRoleStoreAssignedHandler;
use App\Services\Events\Handlers\UserRoleStoreRemovedHandler;
use App\Services\Events\Handlers\UserRoleStoreToggledHandler;
use App\Services\Events\Handlers\UserUpdatedHandler;

class EventRouter
{
    /**
     * Subject → handler map for known inbound subjects.
     * The InboxEventProcessor delegates dispatch to these handlers via the
     * same idempotency path, so each handler only contains domain logic.
     *
     * @var array<string, class-string<EventHandlerInterface>>
     */
    private const HANDLER_MAP = [
        'auth.v1.user.created'  => UserCreatedHandler::class,
        'auth.v1.user.updated'  => UserUpdatedHandler::class,
        'auth.v1.user.deleted'  => UserDeletedHandler::class,
        'auth.v1.store.created' => StoreCreatedHandler::class,
        'auth.v1.store.updated' => StoreUpdatedHandler::class,
        'auth.v1.store.deleted' => StoreDeletedHandler::class,
        'auth.v1.assignment.user_role_store.assigned'      => UserRoleStoreAssignedHandler::class,
        'auth.v1.assignment.user_role_store.removed'       => UserRoleStoreRemovedHandler::class,
        'auth.v1.assignment.user_role_store.toggled'       => UserRoleStoreToggledHandler::class,
        'auth.v1.assignment.user_role_store.bulk_assigned' => UserRoleStoreBulkAssignedHandler::class,
    ];

    public function __construct(private readonly InboxEventProcessor $processor) {}

    /**
     * Route an incoming envelope through idempotency and dispatch.
     *
     * All subjects in HANDLER_MAP are accepted.  Unknown subjects are passed
     * to InboxEventProcessor which treats them as safe no-ops.
     *
     * @param array<string, mixed> $envelope
     */
    public function route(string $subject, array $envelope): InboxEvent
    {
        return $this->processor->process($subject, $envelope);
    }

    /**
     * Entry point for NATS transport layer.
     *
     * Accepts the raw decoded JSON array from a NATS message body, normalises
     * it into the canonical envelope shape expected by InboxEventProcessor, and
     * routes it through the standard idempotency path.
     *
     * Normalization lives here rather than in JetStreamConsumer so the consumer
     * stays transport-only.
     *
     * @param array<string, mixed> $payload  Raw decoded JSON from $msg->payload->body
     * @throws InvalidArgumentException when the payload cannot be normalised
     */
    public function routeNatsPayload(string $subject, array $payload): InboxEvent
    {
        $envelope = self::normalizePayload($subject, $payload);
        return $this->processor->process($subject, $envelope);
    }

    /**
     * Normalise a raw decoded NATS payload into the canonical envelope array.
     *
     * Shape A — company v1 event:
     *   {"event_id":"...","event_type":"auth.v1.user.updated","data":{...}}
     *
     * Shape B — legacy id/subject/payload:
     *   {"id":"...","subject":"auth.v1.user.updated","payload":{...}}
     *
     * Returns:
     *   ['event_id','event_type','occurred_at','source','version','data']
     *
     * @param  array<string, mixed> $decoded  Already json_decode'd payload
     * @return array<string, mixed>
     * @throws InvalidArgumentException when required fields are absent or data is not an array
     */
    public static function normalizePayload(string $subject, array $decoded): array
    {
        // Shape A: {"event_id": ..., "event_type": ..., "data": {...}}
        if (isset($decoded['event_id'])) {
            $data = $decoded['data'] ?? [];
            if (! is_array($data)) {
                throw new InvalidArgumentException('Envelope "data" field must be an array/object.');
            }
            return [
                'event_id'    => (string) $decoded['event_id'],
                'event_type'  => (string) ($decoded['event_type'] ?? $subject),
                'occurred_at' => (string) ($decoded['occurred_at'] ?? ''),
                'source'      => (string) ($decoded['source'] ?? ''),
                'version'     => (int)    ($decoded['version'] ?? 1),
                'data'        => $data,
            ];
        }

        // Shape B: {"id": ..., "subject": ..., "payload": {...}}
        if (isset($decoded['id'])) {
            $data = $decoded['payload'] ?? [];
            if (! is_array($data)) {
                throw new InvalidArgumentException('Envelope "payload" field must be an array/object.');
            }
            return [
                'event_id'    => (string) $decoded['id'],
                'event_type'  => (string) ($decoded['subject'] ?? $subject),
                'occurred_at' => (string) ($decoded['occurred_at'] ?? ''),
                'source'      => (string) ($decoded['source'] ?? ''),
                'version'     => (int)    ($decoded['version'] ?? 1),
                'data'        => $data,
            ];
        }

        throw new InvalidArgumentException(
            'NATS payload is missing required "event_id" or "id" field.'
        );
    }

    /**
     * Resolve the handler class for a subject.
     * Returns NoOpHandler for unknown subjects (safe no-op).
     *
     * @return class-string<EventHandlerInterface>
     */
    public function resolve(string $subject): string
    {
        return self::HANDLER_MAP[$subject] ?? Handlers\NoOpHandler::class;
    }

    /**
     * Returns the handler class for a subject, or null if unknown.
     *
     * @return class-string<EventHandlerInterface>|null
     */
    public static function handlerFor(string $subject): ?string
    {
        return self::HANDLER_MAP[$subject] ?? null;
    }
}
