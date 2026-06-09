# 06 - Laravel Architecture

## Recommended Laravel Setup

Use:

- Laravel 11 or 12
- Laravel Sanctum for API authentication
- MySQL
- Laravel Queues
- PHPUnit or Pest

## Suggested Application Structure

A clean modular structure can be achieved without adding a package.

Example:

```text
app/
  Actions/
    Applications/
      CreateApplicationAction.php
      MoveApplicationStageAction.php
      SubmitApplicantAnswersAction.php
    Workflows/
      PublishWorkflowAction.php
      CopyWorkflowToStoreAction.php
    Documents/
      SubmitApplicantDocumentAction.php
      ApproveApplicantDocumentAction.php
    Automation/
      EvaluateAutomationRulesAction.php
    Integrations/
      PublishOutboxEventsAction.php
      ProcessInboxEventAction.php

  Enums/
    ApplicationStatus.php
    WorkflowStatus.php
    StageType.php
    DocumentStatus.php
    EventStatus.php
    UserRole.php
    UserAccessScope.php

  Http/
    Controllers/
      AuthController.php
      StoreController.php
      UserController.php
      JobOpeningController.php
      WorkflowController.php
      WorkflowStageController.php
      QuestionnaireTemplateController.php
      DocumentTemplateController.php
      ApplicationController.php
      ApplicantAnswerController.php
      ApplicantDocumentController.php
      AutomationRuleController.php
      WorkflowActivityController.php

    Requests/
      Stores/
      Users/
      JobOpenings/
      Workflows/
      Applications/
      Questionnaires/
      Documents/
      AutomationRules/

    Resources/
      StoreResource.php
      JobOpeningResource.php
      WorkflowResource.php
      ApplicationResource.php
      WorkflowActivityResource.php

  Models/
    FranchiseAccount.php
    Store.php
    User.php
    UserStoreAccess.php
    HiringWorkflow.php
    WorkflowStage.php
    WorkflowStageTransition.php
    QuestionnaireTemplate.php
    QuestionnaireQuestion.php
    StageQuestionnaireAssignment.php
    DocumentTemplate.php
    StageDocumentRequirement.php
    Applicant.php
    Application.php
    ApplicantAnswer.php
    ApplicantDocument.php
    ApplicationStageTransition.php
    WorkflowActivity.php
    AutomationRule.php
    ConfigurationCopyLog.php
    OutboxEvent.php
    InboxEvent.php

  Policies/
    StorePolicy.php
    JobOpeningPolicy.php
    HiringWorkflowPolicy.php
    ApplicationPolicy.php

  Services/
    AccessControl/
      StoreAccessService.php
    Workflows/
      WorkflowTransitionValidator.php
      WorkflowVersioningService.php
    Automation/
      RuleConditionEvaluator.php
      RuleActionExecutor.php
    Events/
      OutboxWriter.php
      NatsPublisher.php
      NatsConsumer.php
```

## Models and Relationships

### FranchiseAccount

```php
public function stores()
{
    return $this->hasMany(Store::class);
}

public function users()
{
    return $this->hasMany(User::class);
}
```

### Store

```php
public function franchiseAccount()
{
    return $this->belongsTo(FranchiseAccount::class);
}

public function workflows()
{
    return $this->hasMany(HiringWorkflow::class);
}

public function jobOpenings()
{
    return $this->hasMany(JobOpening::class);
}
```

### User

```php
public function franchiseAccount()
{
    return $this->belongsTo(FranchiseAccount::class);
}

public function storeAccesses()
{
    return $this->hasMany(UserStoreAccess::class);
}

public function stores()
{
    return $this->belongsToMany(Store::class, 'user_store_access');
}
```

### Application

```php
public function applicant()
{
    return $this->belongsTo(Applicant::class);
}

public function jobOpening()
{
    return $this->belongsTo(JobOpening::class);
}

public function currentStage()
{
    return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
}

public function answers()
{
    return $this->hasMany(ApplicantAnswer::class);
}

public function documents()
{
    return $this->hasMany(ApplicantDocument::class);
}

public function activities()
{
    return $this->hasMany(WorkflowActivity::class);
}
```

## Access Control Strategy

Use simplified role and scope.

### Role

Recommended values:

```text
franchise_admin
store_manager
recruiter
viewer
```

### Access Scope

Recommended values:

```text
franchise
multi_store
store
```

### StoreAccessService

Responsibilities:

```text
canAccessStore(User $user, Store|int $store): bool
accessibleStoreIds(User $user): array
scopeQueryToAccessibleStores(Builder $query, User $user, string $storeColumn = 'store_id'): Builder
```

Rules:

```text
If access_scope = franchise:
  user can access all stores in user's franchise_account_id.

If access_scope = multi_store or store:
  user can access only stores in user_store_access.
```

## Transactional Business Actions

Important write operations should use database transactions.

Examples:

- Create application
- Move application stage
- Submit questionnaire answers
- Submit/approve/reject documents
- Copy configuration
- Publish workflow

Example flow:

```php
DB::transaction(function () {
    // Update main data
    // Insert transition/history
    // Insert workflow activity
    // Insert outbox event
});
```

## Workflow Services

### MoveApplicationStageAction

Responsibilities:

```text
1. Authorize access.
2. Load current stage.
3. Validate target stage.
4. Validate allowed transition.
5. Update application.
6. Create transition history.
7. Create activity.
8. Create outbox event.
9. Trigger automation if needed.
```

### WorkflowTransitionValidator

Responsibilities:

```text
- Check that source and destination stages belong to same workflow.
- Check workflow_stage_transitions exists.
- Check manual/automatic flag.
- Evaluate transition conditions if present.
```

## Automation Services

### EvaluateAutomationRulesAction

Input:

```text
Application
Trigger name
Context payload
```

Process:

```text
1. Load active rules for store/workflow/stage and trigger.
2. Sort by priority ascending.
3. Build evaluation context from application, applicant, answers, documents, stage.
4. Evaluate conditions.
5. Execute matching actions.
6. Log activity for executed rules.
```

## Event Services

### OutboxWriter

Creates an `outbox_events` row inside the same DB transaction as the business change.

### PublishOutboxEventsAction

Runs from a queue job or scheduled command.

Process:

```text
1. Fetch pending outbox events where available_at <= now().
2. Publish to NATS.
3. Mark as published on success.
4. Increment attempts and set last_error on failure.
5. If attempts exceed limit, mark as failed.
```

## Suggested Artisan Commands

```text
php artisan nats:publish-outbox
php artisan nats:consume
php artisan automation:evaluate-pending
```

## Suggested Routes File Organization

Use `routes/api.php`, grouped by auth middleware.

```php
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('stores', StoreController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('job-openings', JobOpeningController::class);
    Route::apiResource('workflows', WorkflowController::class);
    Route::apiResource('questionnaire-templates', QuestionnaireTemplateController::class);
    Route::apiResource('document-templates', DocumentTemplateController::class);
    Route::apiResource('applications', ApplicationController::class);
    Route::apiResource('automation-rules', AutomationRuleController::class);
});
```
