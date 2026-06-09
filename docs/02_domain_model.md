# 02 - Domain Model

## Core Entities

### FranchiseAccount

Represents the parent business account.

A franchise account owns multiple stores.

Relationships:

```text
FranchiseAccount has many Stores
FranchiseAccount has many Users
```

### Store

Represents a single business location.

Each store can have its own hiring workflows, job openings, questionnaire templates, document templates, and automation rules.

Relationships:

```text
Store belongs to FranchiseAccount
Store has many JobOpenings
Store has many HiringWorkflows
Store has many QuestionnaireTemplates
Store has many DocumentTemplates
Store has many AutomationRules
```

### User

Represents an internal user.

Access is intentionally simplified. Instead of separate roles and permissions tables, the user has:

```text
role
access_scope
```

Suggested role values:

```text
franchise_admin
store_manager
recruiter
viewer
```

Suggested access scope values:

```text
franchise
multi_store
store
```

Relationships:

```text
User belongs to FranchiseAccount
User has many UserStoreAccess rows
```

### UserStoreAccess

Maps users to stores they can access.

Used when the user does not have full franchise scope.

Example:

```text
User A can access Store 1 and Store 2 only.
```

### JobOpening

Represents a position applicants can apply to.

Each job opening belongs to a store and uses a hiring workflow.

Relationships:

```text
JobOpening belongs to Store
JobOpening belongs to HiringWorkflow
JobOpening has many Applications
```

### Applicant

Represents the person applying for a job.

The applicant entity stores identity/contact information that can be reused if the same person applies to multiple jobs.

Relationships:

```text
Applicant has many Applications
```

### Application

Represents one applicant applying to one job opening.

This is the main workflow-tracked entity.

Relationships:

```text
Application belongs to Applicant
Application belongs to JobOpening
Application belongs to current WorkflowStage
Application has many ApplicantAnswers
Application has many ApplicantDocuments
Application has many ApplicationStageTransitions
Application has many WorkflowActivities
```

Important fields:

```text
current_stage_id
status
applied_at
rejected_at
hired_at
withdrawn_at
```

### HiringWorkflow

Defines the hiring process for a store.

A job opening uses one workflow.

Workflows are versioned because the hiring process can change over time.

Relationships:

```text
HiringWorkflow belongs to Store
HiringWorkflow has many WorkflowStages
HiringWorkflow has many WorkflowStageTransitions
HiringWorkflow has many AutomationRules
```

### WorkflowStage

Represents one step in the workflow.

Examples:

```text
Applied
Screening
Interview
Document Collection
Manager Approval
Hired
Rejected
```

Important fields:

```text
stage_type
position
is_initial
is_terminal
auto_advance_enabled
configuration
```

### WorkflowStageTransition

Defines allowed movement between stages.

This table answers:

```text
Can this application move from Stage A to Stage B?
Is the movement allowed manually?
Is the movement allowed automatically?
Are there conditions?
```

This is configuration.

Do not confuse it with `application_stage_transitions`, which is history.

### QuestionnaireTemplate

Reusable group of questions.

Examples:

```text
Basic Screening Questionnaire
Driver Eligibility Questionnaire
Interview Feedback Form
```

Relationships:

```text
QuestionnaireTemplate belongs to Store
QuestionnaireTemplate has many QuestionnaireQuestions
QuestionnaireTemplate can be assigned to many WorkflowStages
```

### QuestionnaireQuestion

Represents one question inside a questionnaire template.

Supported types can include:

```text
text
number
boolean
date
select
multiselect
file
```

### StageQuestionnaireAssignment

Connects a questionnaire template to a workflow stage.

Example:

```text
Screening Stage requires Basic Screening Questionnaire.
```

### ApplicantAnswer

Stores an applicant's answer to a questionnaire question within an application.

Answers are stored as JSON to support multiple question types.

### DocumentTemplate

Defines a document type required by a store.

Examples:

```text
ID Card
Work Permit
Signed Agreement
Bank Information
```

### StageDocumentRequirement

Assigns a document template to a workflow stage.

Example:

```text
Document Collection stage requires ID Card and Work Permit.
```

### ApplicantDocument

Tracks the applicant's completion status for a required document.

Status values:

```text
pending
submitted
signed
approved
rejected
expired
```

The table models future digital signing with:

```text
external_signature_id
signed_at
```

### AutomationRule

Defines configurable automation behavior.

Rules have:

```text
trigger
conditions
actions
priority
```

Examples:

```text
When answers are submitted, if work_authorization = false, move application to Rejected.
When all documents are approved, move application to Manager Approval.
```

### ApplicationStageTransition

Historical record of actual stage movement for an application.

Example:

```text
Application moved from Screening to Interview by automation rule X.
```

### WorkflowActivity

General audit/activity log for meaningful workflow events.

Examples:

```text
Application created
Questionnaire submitted
Document uploaded
Document approved
Stage changed
Automation rule executed
Application hired
Application rejected
```

### ConfigurationCopyLog

Tracks copy/reuse of store configuration from one store to another.

The copied configuration becomes store-specific after copy.

### OutboxEvent

Stores events that this service needs to publish to NATS.

Used for local consistency.

### InboxEvent

Stores events received from NATS to ensure idempotent processing.
