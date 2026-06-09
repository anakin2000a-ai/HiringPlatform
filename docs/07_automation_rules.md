# 07 - Automation Rules

## Purpose

Automation rules allow the system to react to applicant data, questionnaire answers, workflow progress, document status, or other hiring information.

Rules can move applications between stages or perform other business actions.

## Rule Structure

Table: `automation_rules`

Important fields:

```text
store_id
hiring_workflow_id
workflow_stage_id
name
trigger
conditions
actions
priority
is_active
```

## Supported Triggers

Recommended trigger values:

```text
application_created
stage_entered
answer_submitted
document_submitted
document_signed
document_approved
document_rejected
application_hired
application_rejected
```

## Condition Schema

Use a JSON structure that supports `all` and `any` groups.

Example:

```json
{
  "all": [
    {
      "field": "answers.work_authorization",
      "operator": "equals",
      "value": true
    },
    {
      "field": "answers.weekend_availability",
      "operator": "equals",
      "value": true
    }
  ]
}
```

Example with `any`:

```json
{
  "any": [
    {
      "field": "answers.previous_experience_years",
      "operator": "greater_than_or_equal",
      "value": 1
    },
    {
      "field": "answers.referral_source",
      "operator": "equals",
      "value": "employee_referral"
    }
  ]
}
```

## Supported Operators

Recommended operators:

```text
equals
not_equals
greater_than
greater_than_or_equal
less_than
less_than_or_equal
contains
not_contains
in
not_in
exists
not_exists
is_empty
is_not_empty
```

## Supported Field Paths

Examples:

```text
applicant.email
applicant.phone
application.status
application.score
current_stage.slug
answers.work_authorization
answers.weekend_availability
documents.id_card.status
documents.work_permit.status
```

## Action Schema

Actions should be an array to allow multiple actions per matching rule.

Example:

```json
[
  {
    "type": "move_to_stage",
    "stage_slug": "interview"
  },
  {
    "type": "publish_event",
    "event_type": "application.qualified"
  }
]
```

## Supported Actions

Recommended action types:

```text
move_to_stage
reject_application
mark_hired
create_activity
publish_event
set_score
increment_score
```

## Example Rules

### Reject Applicant Without Work Authorization

```json
{
  "name": "Reject if not authorized to work",
  "trigger": "answer_submitted",
  "conditions": {
    "all": [
      {
        "field": "answers.work_authorization",
        "operator": "equals",
        "value": false
      }
    ]
  },
  "actions": [
    {
      "type": "move_to_stage",
      "stage_slug": "rejected"
    }
  ],
  "priority": 10
}
```

### Move to Interview if Screening Passed

```json
{
  "name": "Move qualified applicants to interview",
  "trigger": "answer_submitted",
  "conditions": {
    "all": [
      {
        "field": "answers.work_authorization",
        "operator": "equals",
        "value": true
      },
      {
        "field": "answers.weekend_availability",
        "operator": "equals",
        "value": true
      }
    ]
  },
  "actions": [
    {
      "type": "move_to_stage",
      "stage_slug": "interview"
    }
  ],
  "priority": 20
}
```

### Move to Approval After Required Documents Approved

```json
{
  "name": "Move to approval when documents complete",
  "trigger": "document_approved",
  "conditions": {
    "all": [
      {
        "field": "documents.all_required.status",
        "operator": "equals",
        "value": "approved"
      }
    ]
  },
  "actions": [
    {
      "type": "move_to_stage",
      "stage_slug": "manager-approval"
    }
  ],
  "priority": 30
}
```

## Evaluation Algorithm

```text
1. Receive trigger and application.
2. Load active automation rules matching:
   - store_id
   - hiring_workflow_id, if set
   - workflow_stage_id, if set
   - trigger
3. Sort by priority ascending.
4. Build evaluation context.
5. Evaluate each rule's conditions.
6. Execute actions for matching rules.
7. Log activity for each executed rule.
8. Add outbox event if rule execution changes meaningful state.
```

## Evaluation Context Example

```json
{
  "applicant": {
    "email": "john@example.com",
    "phone": "+15555550123"
  },
  "application": {
    "id": 10,
    "status": "active",
    "score": 0
  },
  "current_stage": {
    "id": 2,
    "slug": "screening",
    "stage_type": "screening"
  },
  "answers": {
    "work_authorization": true,
    "weekend_availability": true
  },
  "documents": {
    "id_card": {
      "status": "approved"
    },
    "work_permit": {
      "status": "pending"
    }
  }
}
```

## Safety Rules

To avoid infinite loops:

- Do not re-run the same rule repeatedly in the same transaction.
- Avoid triggering `stage_entered` recursively without guard logic.
- Record rule execution in activity metadata.
- Validate automatic stage movement through `workflow_stage_transitions`.
