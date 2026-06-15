<?php

namespace App\Services\Events;

use App\Enums\InboxEventStatus;
use App\Models\Applicant;
use App\Models\InboxEvent;
use App\Models\Store;
use App\Models\User;
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
use InvalidArgumentException;

class InboxEventProcessor
{
    /**
     * Process an incoming event envelope idempotently.
     *
     * Expected envelope shape:
     * {
     *   "event_id":    "uuid",
     *   "event_type":  "stores.store.updated",
     *   "occurred_at": "2026-01-01T00:00:00Z",
     *   "source":      "external-service-name",
     *   "version":     1,
     *   "data":        { ... }
     * }
     *
     * Idempotency: if an inbox_events row with the same event_id already exists
     * and its status is 'processed', the call returns immediately without
     * re-processing.  A 'pending' or 'failed' row is retried.
     *
     * @param array<string, mixed> $envelope
     * @throws InvalidArgumentException when event_id is missing
     */
    public function process(string $subject, array $envelope): InboxEvent
    {
        $eventId = $envelope['event_id'] ?? null;

        if (empty($eventId)) {
            throw new InvalidArgumentException(
                'Incoming event envelope is missing the required event_id field.'
            );
        }

        $existing = InboxEvent::where('event_id', $eventId)->first();

        // Already processed — skip idempotently.
        if ($existing !== null && $existing->status === InboxEventStatus::Processed) {
            return $existing;
        }

        // Create the row on first sight; reuse it on retry.
        $inboxEvent = $existing ?? InboxEvent::create([
            'event_id'   => $eventId,
            'subject'    => $subject,
            'event_type' => $envelope['event_type'] ?? null,
            'payload'    => $envelope,
            'status'     => InboxEventStatus::Pending,
            'attempts'   => 0,
        ]);

        try {
            $this->dispatch($subject, $envelope);

            $inboxEvent->update([
                'status'       => InboxEventStatus::Processed,
                'processed_at' => now(),
                'last_error'   => null,
            ]);
        } catch (\Throwable $e) {
            $inboxEvent->update([
                'attempts'   => $inboxEvent->attempts + 1,
                'last_error' => $e->getMessage(),
                'failed_at'  => now(),
                'status'     => InboxEventStatus::Failed,
            ]);

            throw $e;
        }

        return $inboxEvent->fresh();
    }

    /**
     * Route the envelope to the appropriate subject handler.
     * Unknown subjects are accepted silently (safe no-op).
     *
     * @param array<string, mixed> $envelope
     */
    protected function dispatch(string $subject, array $envelope): void
    {
        $data = $envelope['data'] ?? [];

        match ($subject) {
            // v1 company-standard subjects — routed to typed handler classes
            'auth.v1.user.created'          => (new UserCreatedHandler())->handle($data),
            'auth.v1.user.updated'          => (new UserUpdatedHandler())->handle($data),
            'auth.v1.user.deleted'          => (new UserDeletedHandler())->handle($data),
            'auth.v1.store.created'         => (new StoreCreatedHandler())->handle($data),
            'auth.v1.store.updated'         => (new StoreUpdatedHandler())->handle($data),
            'auth.v1.store.deleted'         => (new StoreDeletedHandler())->handle($data),
            'auth.v1.assignment.user_role_store.assigned'      => (new UserRoleStoreAssignedHandler())->handle($data),
            'auth.v1.assignment.user_role_store.removed'       => (new UserRoleStoreRemovedHandler())->handle($data),
            'auth.v1.assignment.user_role_store.toggled'       => (new UserRoleStoreToggledHandler())->handle($data),
            'auth.v1.assignment.user_role_store.bulk_assigned' => (new UserRoleStoreBulkAssignedHandler())->handle($data),
            // Legacy / internal subjects kept for backward compatibility
            'stores.store.created'          => $this->handleStoreCreated($data),
            'stores.store.updated'          => $this->handleStoreUpdated($data),
            'users.user.updated'            => $this->handleUserUpdated($data),
            'applicants.applicant.updated'  => $this->handleApplicantUpdated($data),
            'employees.employee.created'    => null,
            default                         => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function handleStoreCreated(array $data): void
    {
        // Conservative: external store creation requires franchise context that
        // is not available in an incoming event payload. Mark as processed only.
    }

    /** @param array<string, mixed> $data */
    private function handleStoreUpdated(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        $store = Store::find($id);
        if ($store === null) {
            return;
        }

        $updates = array_intersect_key($data, array_flip(['store_name']));

        if (! empty($updates)) {
            $store->update($updates);
        }
    }

    /** @param array<string, mixed> $data */
    private function handleUserUpdated(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        $user = User::find($id);
        if ($user === null) {
            return;
        }

        $updates = array_intersect_key($data, array_flip(['name', 'email']));

        if (! empty($updates)) {
            $user->update($updates);
        }
    }

    /** @param array<string, mixed> $data */
    private function handleApplicantUpdated(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        $applicant = Applicant::find($id);
        if ($applicant === null) {
            return;
        }

        $updates = array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone']));

        if (! empty($updates)) {
            $applicant->update($updates);
        }
    }
}
