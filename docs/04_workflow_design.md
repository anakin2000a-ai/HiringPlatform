# 04 - Workflow Design

## Goal

The workflow engine controls how an applicant application moves through a hiring process.

The workflow must support:

- Store-specific configuration
- Custom stages
- Manual movement by authorized users
- Automatic movement by automation rules
- Activity history
- Event publishing
- Versioned workflow configuration

## Main Workflow Example

A store creates a cashier hiring workflow:

```text
1. Applied
2. Screening
3. Interview
4. Document Collection
5. Manager Approval
6. Hired
7. Rejected
```

## Stage Types

Recommended stage types:

```text
application
screening
interview
documents
approval
onboarding
hired
rejected
custom
```

`stage_type` describes behavior. `name` is user-facing and customizable.

Example:

```text
name: Phone Screen
stage_type: screening
```

## Initial Stage

Each workflow must have exactly one initial stage.

Example:

```text
Applied
```

When an application is created, `current_stage_id` should be set to the workflow's initial stage.

## Terminal Stages

Terminal stages end the workflow.

Examples:

```text
Hired
Rejected
Withdrawn
```

When an application enters a terminal hired stage:

```text
applications.status = hired
applications.hired_at = now()
```

When an application enters a rejected stage:

```text
applications.status = rejected
applications.rejected_at = now()
```

## Allowed Transitions

Use `workflow_stage_transitions` to configure valid movements.

Example:

```text
Applied -> Screening
Screening -> Interview
Screening -> Rejected
Interview -> Document Collection
Interview -> Rejected
Document Collection -> Manager Approval
Manager Approval -> Hired
Manager Approval -> Rejected
```

Before moving an application, the system must validate:

1. The source stage matches `applications.current_stage_id`.
2. The destination stage belongs to the same workflow.
3. A `workflow_stage_transitions` row exists.
4. The transition is allowed for manual or automatic movement.
5. Optional transition conditions pass.

## Manual Stage Movement

Endpoint example:

```http
POST /api/applications/{application}/move-stage
```

Request example:

```json
{
  "to_stage_id": 5,
  "reason": "Candidate passed interview"
}
```

Service behavior:

```text
1. Load application with job opening and workflow.
2. Check user store access.
3. Validate target stage belongs to the workflow.
4. Validate allowed transition.
5. Update applications.current_stage_id.
6. Update applications.status if terminal stage.
7. Insert application_stage_transitions row.
8. Insert workflow_activities row.
9. Insert outbox_events row.
10. Commit transaction.
```

## Automatic Stage Movement

Automation can trigger from events such as:

```text
application_created
answer_submitted
stage_entered
document_submitted
document_approved
document_rejected
```

Example automatic rule:

```text
Trigger: answer_submitted
Condition: answers.work_authorization = false
Action: move_to_stage rejected
```

## Application Creation Flow

```text
1. Applicant submits application for job opening.
2. System creates or reuses applicant by email/phone.
3. System creates applications row.
4. System sets current stage to workflow initial stage.
5. System creates application_stage_transitions row with from_stage_id = null.
6. System creates workflow_activities row.
7. System creates applicant_documents rows for documents required by the current stage, if applicable.
8. System creates outbox event: hiring.application.created.
9. System evaluates automation rules for application_created.
```

## Questionnaire Submission Flow

```text
1. Applicant submits answers for a questionnaire assigned to the current stage.
2. System validates required questions.
3. System upserts applicant_answers.
4. System creates workflow_activities row.
5. System creates outbox event: hiring.questionnaire.submitted.
6. System evaluates automation rules for answer_submitted.
```

## Document Flow

```text
1. Application enters a document stage.
2. System creates pending applicant_documents for required documents.
3. Applicant submits/upload document.
4. Status changes to submitted.
5. Manager approves, rejects, or marks signed.
6. Activity is logged.
7. Event is added to outbox.
8. Automation rules are evaluated.
```

## Workflow Versioning

Workflow changes should be controlled.

Recommended policy:

- Draft workflows can be edited directly.
- Active workflows should not be structurally edited if applications are already using them.
- For major changes, create a new version.
- Existing applications continue using their original workflow version.
- New job openings can use the new active workflow version.

Example:

```text
Cashier Workflow v1 = active
Cashier Workflow v2 = draft
Publish v2 -> v1 archived, v2 active
```

## Configuration Copy Between Stores

Copying configuration should duplicate records, not share them.

When copying from Store A to Store B:

```text
1. Copy hiring_workflows.
2. Copy workflow_stages.
3. Re-map copied stage IDs.
4. Copy workflow_stage_transitions with new stage IDs.
5. Copy questionnaire_templates and questionnaire_questions.
6. Copy stage_questionnaire_assignments with new IDs.
7. Copy document_templates.
8. Copy stage_document_requirements with new IDs.
9. Copy automation_rules with remapped stage/workflow IDs.
10. Insert configuration_copy_logs row.
```

After copy, Store B owns its copy independently.
