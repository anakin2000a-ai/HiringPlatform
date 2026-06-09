# 08 - NATS Event Integration

## Goal

The platform participates in a wider microservices environment.

It should:

1. Consume relevant events from NATS.
2. Update local data where appropriate.
3. Publish meaningful hiring workflow events to NATS.
4. Remain consistent even if NATS is temporarily unavailable.

## Recommended Pattern

Use:

```text
Transactional Outbox Pattern
Idempotent Inbox Pattern
```

## Why Outbox

Business changes and event creation should happen in the same database transaction.

Example:

```text
Move application to Hired
Insert workflow activity
Insert outbox event
Commit transaction
Publish event later from outbox worker
```

This prevents a situation where the database changes but the event is lost.

## Outbox Table

`outbox_events` stores events waiting to be published.

Important fields:

```text
event_id
subject
event_type
payload
status
attempts
available_at
published_at
last_error
```

## Inbox Table

`inbox_events` stores events received from NATS.

Important fields:

```text
event_id
subject
event_type
payload
status
attempts
processed_at
last_error
```

The unique `event_id` prevents duplicate processing.

## Event Envelope

All events should use a consistent envelope.

Example:

```json
{
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "event_type": "hiring.application.stage_changed",
  "occurred_at": "2026-01-01T12:00:00Z",
  "source": "hiring-workflow-service",
  "version": 1,
  "correlation_id": "req-123",
  "data": {
    "application_id": 10,
    "store_id": 1,
    "job_opening_id": 5,
    "from_stage": {
      "id": 2,
      "slug": "screening"
    },
    "to_stage": {
      "id": 3,
      "slug": "interview"
    },
    "transition_type": "automatic"
  }
}
```

## Suggested Subject Naming

Use predictable subjects.

Outgoing subjects:

```text
hiring.application.created
hiring.application.stage_changed
hiring.application.hired
hiring.application.rejected
hiring.questionnaire.submitted
hiring.document.submitted
hiring.document.approved
hiring.document.rejected
hiring.workflow.published
```

Incoming subjects:

```text
stores.store.created
stores.store.updated
users.user.created
users.user.updated
applicants.applicant.updated
employees.employee.created
```

## Events Published by This Service

### Application Created

Subject:

```text
hiring.application.created
```

Payload data:

```json
{
  "application_id": 10,
  "applicant_id": 7,
  "job_opening_id": 5,
  "store_id": 1,
  "current_stage_id": 1,
  "status": "active"
}
```

### Stage Changed

Subject:

```text
hiring.application.stage_changed
```

Payload data:

```json
{
  "application_id": 10,
  "from_stage_id": 2,
  "to_stage_id": 3,
  "transition_type": "manual",
  "changed_by": 4
}
```

### Application Hired

Subject:

```text
hiring.application.hired
```

Payload data:

```json
{
  "application_id": 10,
  "applicant_id": 7,
  "job_opening_id": 5,
  "store_id": 1,
  "hired_at": "2026-01-01T12:00:00Z"
}
```

## Events Consumed by This Service

### Store Created / Updated

Possible behavior:

```text
Create or update local stores table.
```

### User Updated

Possible behavior:

```text
Update local user details/status if user data is owned by another service.
```

### Applicant Updated

Possible behavior:

```text
Update local applicant contact information.
```

### Employee Created

Possible behavior:

```text
If employee is created from a hired application, mark application integration metadata.
```

## Retry Behavior

### Outbox Retry

```text
pending -> publishing -> published
pending -> publishing -> failed
```

Recommended behavior:

```text
- attempts increment after every failed publish.
- available_at is delayed using exponential backoff.
- after max attempts, status becomes failed.
```

Example backoff:

```text
attempt 1: retry after 1 minute
attempt 2: retry after 5 minutes
attempt 3: retry after 15 minutes
attempt 4: retry after 1 hour
```

### Inbox Retry

```text
pending -> processing -> processed
pending -> processing -> failed
```

If processing fails:

```text
- increment attempts
- store last_error
- retry later
```

## Idempotency

Every event must include a unique `event_id`.

When receiving an event:

```text
1. Check inbox_events by event_id.
2. If already processed, skip.
3. If not found, insert as pending.
4. Process event.
5. Mark processed.
```

## Laravel Implementation Options

For local assessment implementation, NATS can be handled by an adapter interface.

```php
interface EventBusPublisher
{
    public function publish(string $subject, array $payload): void;
}
```

Implementations:

```text
NatsEventBusPublisher
FakeEventBusPublisher
LogEventBusPublisher
```

This makes integration testable without requiring real NATS.

## Testing Strategy

Tests should verify:

```text
- Business actions create outbox events.
- Outbox publisher marks event as published on success.
- Outbox publisher increments attempts on failure.
- Inbox processor skips duplicate event_id.
- Incoming event updates local data.
```
