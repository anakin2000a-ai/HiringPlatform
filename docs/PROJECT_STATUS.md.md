# 11 - Assumptions and Tradeoffs

## Assumptions

### 1. One Franchise Account per User

Each internal user belongs to one franchise account.

A user with `access_scope = franchise` can access all stores in that franchise account.

### 2. Simplified Authorization Model

The system does not implement separate roles, permissions, and user_roles tables.

Instead, authorization uses:

```text
users.role
users.access_scope
user_store_access
```

This is sufficient for the assessment while remaining extensible.

### 3. Store-Specific Configuration

Workflows, questionnaires, document templates, and automation rules belong to stores.

When configuration is copied between stores, records are duplicated and become independent.

### 4. Application Is the Workflow Entity

The applicant is the person.

The application is the person's application to a specific job opening.

Workflow state belongs to the application, not the applicant.

### 5. Workflow Versioning

Active workflows should not be structurally modified once used by applications.

Major changes should create a new workflow version.

Existing applications continue on their original workflow version.

### 6. NATS Integration Can Be Mocked Locally

The implementation can use an interface-based event publisher so tests do not require a live NATS server.

A real NATS adapter can be added later.

### 7. Digital Signing Is Modeled, Not Integrated

The system tracks signing-related fields:

```text
external_signature_id
signed_at
```

But it does not integrate with DocuSign, Adobe Sign, or any external provider.

## Tradeoffs

### 1. JSON Conditions and Actions for Automation Rules

Automation rule conditions and actions are stored as JSON.

Benefits:

```text
- Flexible
- Easy to configure through API
- Avoids many tables for rule logic
- Good for assessment scope
```

Costs:

```text
- Harder to query deeply in SQL
- Requires careful validation
- Requires a well-tested evaluator
```

### 2. JSON Answers

Applicant answers are stored as JSON.

Benefits:

```text
- Supports text, number, boolean, select, multiselect, date
- Flexible for dynamic questions
```

Costs:

```text
- Less strict than typed columns
- Reporting on answers may require JSON queries or derived reporting tables later
```

### 3. Simplified User Roles

Using `users.role` instead of full RBAC reduces complexity.

Benefits:

```text
- Faster implementation
- Easier assessment review
- Good enough for known roles
```

Costs:

```text
- Less flexible than full permission model
- Future enterprise permissions may require migration
```

### 4. Outbox Adds Complexity but Improves Reliability

Using outbox/inbox tables adds implementation work.

Benefits:

```text
- Prevents lost events
- Makes event publishing retryable
- Makes integration testable
```

Costs:

```text
- Requires scheduled job/queue worker
- Requires cleanup policy for old events
```

## Important Design Decisions to Explain in Review

### Why Application Has Current Stage

`applications.current_stage_id` provides quick access to the current workflow position.

Historical movements are stored separately in `application_stage_transitions`.

### Why WorkflowStageTransition Exists

`workflow_stage_transitions` defines what is allowed.

`application_stage_transitions` records what happened.

Both are needed.

### Why Questionnaire Templates Are Separate

Templates allow reuse and versioning of question sets.

This matches the requirement for questionnaire templates better than attaching raw questions directly to stages.

### Why Documents Are Split into Templates, Requirements, and Applicant Documents

```text
document_templates = what document type exists
stage_document_requirements = where/when it is required
applicant_documents = applicant-specific completion status
```

### Why Outbox/Inbox Is Used

Outbox ensures that state changes and event intent are stored in the same transaction.

Inbox ensures incoming events are processed idempotently.

## Possible Future Improvements

```text
- Full RBAC with permissions
- Applicant portal authentication
- File upload storage integration
- Real NATS adapter
- Real digital signing provider
- Email/SMS notifications
- Advanced reporting tables
- Workflow visual builder frontend
- Rule builder UI
```
