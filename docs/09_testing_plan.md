# 09 - Testing Plan

## Testing Goal

Automated tests should prove that the main business flows are correct.

Use Feature tests for API behavior and Unit tests for isolated services such as automation rule evaluation.

## Recommended Test Types

```text
Feature tests:
- API endpoints
- Authentication
- Authorization
- Application workflow movement
- Document submission and approval
- Outbox event creation

Unit tests:
- StoreAccessService
- WorkflowTransitionValidator
- RuleConditionEvaluator
- RuleActionExecutor
- Event envelope builder
```

## Factory Requirements

Create factories for:

```text
FranchiseAccountFactory
StoreFactory
UserFactory
HiringWorkflowFactory
WorkflowStageFactory
WorkflowStageTransitionFactory
QuestionnaireTemplateFactory
QuestionnaireQuestionFactory
DocumentTemplateFactory
StageDocumentRequirementFactory
JobOpeningFactory
ApplicantFactory
ApplicationFactory
AutomationRuleFactory
```

## Seeder Requirements

Create a demo seeder that builds:

```text
1 franchise account
3 stores
1 franchise admin
2 store managers
1 cashier workflow
stages:
  - Applied
  - Screening
  - Interview
  - Document Collection
  - Manager Approval
  - Hired
  - Rejected
questionnaire:
  - Basic Screening Questionnaire
documents:
  - ID Card
  - Work Permit
automation rules:
  - Reject if not authorized to work
  - Move to interview if screening passed
```

## Critical Feature Tests

### Auth Test

```text
- User can login with valid credentials.
- User cannot login with invalid credentials.
- Authenticated requests require token.
```

### Store Access Test

```text
Given user has access to Store A only
When user requests applications for Store B
Then API returns 403 or hides Store B data.
```

### Workflow Creation Test

```text
- User creates workflow.
- User adds stages.
- Only one initial stage is allowed.
- Stage slugs are unique per workflow.
```

### Stage Transition Configuration Test

```text
- User creates transition Applied -> Screening.
- Duplicate transition cannot be created.
- Transition stages must belong to same workflow.
```

### Application Creation Test

```text
Given published job opening with workflow
When applicant applies
Then applicant is created
And application is created
And current_stage_id equals initial stage
And activity is logged
And outbox event is created
```

### Manual Stage Movement Test

```text
Given application is in Screening
And transition Screening -> Interview exists
When authorized user moves application to Interview
Then current_stage_id changes
And application_stage_transitions row is created
And workflow_activities row is created
And outbox event is created
```

### Invalid Stage Movement Test

```text
Given application is in Applied
And no transition Applied -> Hired exists
When user tries to move to Hired
Then API returns validation error or 422
And application remains unchanged
```

### Questionnaire Submission Test

```text
Given Screening stage has required questionnaire
When applicant submits all required answers
Then applicant_answers are saved
And activity is logged
And automation rules are evaluated
```

### Automation Reject Test

```text
Given rule rejects when work_authorization = false
When applicant submits answer work_authorization = false
Then application moves to Rejected
And status becomes rejected
And rejected_at is set
And transition is recorded as automatic
```

### Document Lifecycle Test

```text
Given application is in Document Collection stage
And ID Card is required
When document is submitted
Then status becomes submitted
When manager approves it
Then status becomes approved
And activity is logged
And outbox event is created
```

### Configuration Copy Test

```text
Given Store A has workflow, stages, questionnaires, documents, and rules
When user copies configuration to Store B
Then Store B has duplicated configuration
And IDs are different
And copied config belongs to Store B
And configuration_copy_logs row is created
```

### Outbox Publisher Test

```text
Given pending outbox event
When publisher succeeds
Then status becomes published
And published_at is set
```

### Outbox Failure Test

```text
Given pending outbox event
When publisher fails
Then attempts increment
And last_error is stored
And status remains pending or becomes failed after max attempts
```

### Inbox Idempotency Test

```text
Given event_id already processed
When same event is received again
Then it is skipped
And no duplicate business update occurs
```

## Suggested Test File Organization

```text
tests/
  Feature/
    AuthTest.php
    StoreAccessTest.php
    WorkflowManagementTest.php
    ApplicationCreationTest.php
    ApplicationStageMovementTest.php
    QuestionnaireSubmissionTest.php
    DocumentLifecycleTest.php
    ConfigurationCopyTest.php
    OutboxEventTest.php
    InboxEventTest.php

  Unit/
    StoreAccessServiceTest.php
    WorkflowTransitionValidatorTest.php
    RuleConditionEvaluatorTest.php
    RuleActionExecutorTest.php
```

## Minimum Test Coverage for Assessment

If time is limited, prioritize:

```text
1. Store access enforcement
2. Application creation starts at initial stage
3. Valid manual stage movement
4. Invalid stage movement is rejected
5. Questionnaire answer triggers automation reject
6. Document approval flow
7. Outbox event is created during important changes
```
