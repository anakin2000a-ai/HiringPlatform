# 10 - Implementation Phases for Claude

Use this file to split implementation work into clear tasks for Claude.

Each phase should be implemented, tested, and committed before moving to the next phase.

## Phase 1 - Laravel Project Setup

Ask Claude:

```text
Create a Laravel API project for a store-based hiring workflow platform.
Use Laravel Sanctum for authentication, MySQL for database, and PHPUnit/Pest for testing.
Set up API routes, auth login/logout, base test configuration, and README setup instructions.
```

Deliverables:

```text
- Laravel project setup
- Sanctum installed/configured
- AuthController
- Login/logout endpoints
- Basic User factory
- Auth tests
```

## Phase 2 - Core Franchise, Store, User Access Model

Ask Claude:

```text
Implement franchise_accounts, stores, users, and user_store_access tables and models.
Do not create roles/permissions tables. Use users.role and users.access_scope.
Implement StoreAccessService that checks whether a user can access a store.
Add API endpoints for stores and users with store-scoped access behavior.
Add tests proving users cannot access stores outside their scope.
```

Deliverables:

```text
- Migrations
- Models and relationships
- StoreAccessService
- StoreController
- UserController
- Form Requests
- Resources
- Store access tests
```

## Phase 3 - Workflow Configuration

Ask Claude:

```text
Implement hiring workflows, workflow stages, and workflow stage transitions.
Support workflow status draft/active/archived, version, parent_workflow_id, published_at, archived_at.
Add endpoints to create workflows, add stages, create transitions, and publish workflows.
Ensure only one initial stage per workflow.
Ensure transitions reference stages in the same workflow.
Add tests for workflow creation and transition validation.
```

Deliverables:

```text
- hiring_workflows migration/model
- workflow_stages migration/model
- workflow_stage_transitions migration/model
- WorkflowController
- WorkflowStageController
- Transition endpoints
- Publish workflow action
- Tests
```

## Phase 4 - Job Openings

Ask Claude:

```text
Implement job openings linked to stores and hiring workflows.
A job opening must use a workflow from the same store.
Add endpoints to create, update, list, show, publish, and close job openings.
Apply store access restrictions to all endpoints.
Add tests.
```

Deliverables:

```text
- job_openings migration/model
- JobOpeningController
- Form Requests
- Resources
- Publish/close actions
- Tests
```

## Phase 5 - Applicants and Applications

Ask Claude:

```text
Implement applicants and applications.
When an applicant applies to a job opening, create or reuse applicant by email/phone, create application, set current_stage_id to the workflow initial stage, set applied_at, log activity, create initial stage transition with from_stage_id null, and create an outbox event.
Add endpoints to create/list/show applications.
Add tests for application creation.
```

Deliverables:

```text
- applicants migration/model
- applications migration/model
- application_stage_transitions migration/model
- workflow_activities migration/model
- outbox_events migration/model
- CreateApplicationAction
- ApplicationController
- Tests
```

## Phase 6 - Manual Stage Movement

Ask Claude:

```text
Implement MoveApplicationStageAction.
It must validate store access, validate that target stage belongs to the application's workflow, validate allowed transition, update current_stage_id, update application status if target stage is terminal, create application_stage_transitions, create workflow_activities, and create outbox_events.
Add POST /api/applications/{application}/move-stage.
Add tests for valid and invalid stage movement.
```

Deliverables:

```text
- MoveApplicationStageAction
- WorkflowTransitionValidator
- move-stage endpoint
- Tests
```

## Phase 7 - Questionnaire Templates and Applicant Answers

Ask Claude:

```text
Implement questionnaire_templates, questionnaire_questions, stage_questionnaire_assignments, and applicant_answers.
Add APIs to create questionnaire templates, add questions, assign questionnaire to workflow stages, and submit applicant answers.
Validate required questions.
When answers are submitted, create activity and outbox event.
Prepare hook to evaluate automation rules after answer submission.
Add tests.
```

Deliverables:

```text
- Migrations/models
- QuestionnaireTemplateController
- Stage questionnaire assignment endpoint
- SubmitApplicantAnswersAction
- Tests
```

## Phase 8 - Documents

Ask Claude:

```text
Implement document_templates, stage_document_requirements, and applicant_documents.
When an application enters a stage with required documents, create pending applicant_documents records.
Add APIs to create document templates, assign document requirements to stages, submit applicant document, approve document, reject document, and mark document signed.
Create activities and outbox events for document status changes.
Add tests.
```

Deliverables:

```text
- Migrations/models
- DocumentTemplateController
- ApplicantDocumentController
- Submit/approve/reject actions
- Tests
```

## Phase 9 - Automation Rules

Ask Claude:

```text
Implement automation_rules with JSON conditions and actions.
Create RuleConditionEvaluator and RuleActionExecutor.
Support triggers: application_created, stage_entered, answer_submitted, document_submitted, document_approved, document_rejected.
Support actions: move_to_stage, reject_application, mark_hired, create_activity, publish_event, set_score, increment_score.
Add automation rule CRUD endpoints.
Add tests for automatic rejection and automatic move to next stage.
```

Deliverables:

```text
- automation_rules migration/model
- AutomationRuleController
- EvaluateAutomationRulesAction
- RuleConditionEvaluator
- RuleActionExecutor
- Tests
```

## Phase 10 - Configuration Copy Between Stores

Ask Claude:

```text
Implement configuration copy from one store to another.
Copy workflows, stages, transitions, questionnaires, questions, stage-questionnaire assignments, document templates, stage-document requirements, and automation rules.
All copied records must belong to the target store and have new IDs.
Create configuration_copy_logs row.
Add endpoint POST /api/stores/{sourceStore}/copy-configuration.
Add tests proving copied config is independent.
```

Deliverables:

```text
- configuration_copy_logs migration/model
- CopyStoreConfigurationAction
- Endpoint
- Tests
```

## Phase 11 - NATS Outbox/Inbox Integration

Ask Claude:

```text
Implement event-driven integration using an EventBusPublisher interface.
Provide a fake/log publisher for local development and testability.
Implement outbox publisher command/job that publishes pending outbox_events and marks them published or failed with retry attempts.
Implement inbox event processor with idempotency using event_id.
Document NATS subjects and event envelope.
Add tests for outbox success, outbox failure, and inbox duplicate handling.
```

Deliverables:

```text
- EventBusPublisher interface
- FakeEventBusPublisher
- PublishOutboxEvents command/job
- InboxEventProcessor
- Tests
- Documentation
```

## Phase 12 - API Documentation and Final README

Ask Claude:

```text
Create API documentation for all endpoints with request/response examples.
Update README with setup instructions, architecture overview, design decisions, assumptions, testing instructions, and event integration explanation.
```

Deliverables:

```text
- README.md
- API.md
- DESIGN_DECISIONS.md
- EVENT_INTEGRATION.md
- TESTING.md
```

## Suggested Commit Plan

```text
commit 1: project setup and auth
commit 2: franchise/store/user access model
commit 3: workflow configuration
commit 4: job openings
commit 5: applicants/applications
commit 6: stage movement
commit 7: questionnaires
commit 8: documents
commit 9: automation rules
commit 10: configuration copy
commit 11: NATS outbox/inbox
commit 12: docs and cleanup
```
