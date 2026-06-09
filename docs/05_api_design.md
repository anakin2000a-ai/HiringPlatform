# 05 - API Design

## API Principles

The API should be:

- RESTful
- JSON-only
- Store-access aware
- Validated with Laravel Form Requests
- Paginated for list endpoints
- Filterable where useful
- Consistent in error responses

## Authentication

Recommended: Laravel Sanctum.

### Login

```http
POST /api/auth/login
```

Request:

```json
{
  "email": "admin@example.com",
  "password": "password"
}
```

Response:

```json
{
  "token": "plain-text-token",
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com",
    "role": "franchise_admin",
    "access_scope": "franchise"
  }
}
```

### Logout

```http
POST /api/auth/logout
```

## Stores

### List Stores

```http
GET /api/stores
```

Filters:

```text
status
city
state
country
search
```

Access behavior:

- `franchise` scope: all stores in user's franchise.
- `store` or `multi_store` scope: only stores in `user_store_access`.

### Create Store

```http
POST /api/stores
```

Request:

```json
{
  "name": "Store A",
  "code": "STORE-A",
  "city": "New York",
  "state": "NY",
  "country": "US",
  "timezone": "America/New_York"
}
```

### Update Store

```http
PATCH /api/stores/{store}
```

## Users

### List Users

```http
GET /api/users
```

### Create User

```http
POST /api/users
```

Request:

```json
{
  "name": "Store Manager",
  "email": "manager@example.com",
  "password": "password",
  "role": "store_manager",
  "access_scope": "multi_store",
  "store_ids": [1, 2]
}
```

## Job Openings

### List Job Openings

```http
GET /api/job-openings
```

Filters:

```text
store_id
status
search
employment_type
```

### Create Job Opening

```http
POST /api/job-openings
```

Request:

```json
{
  "store_id": 1,
  "hiring_workflow_id": 3,
  "title": "Cashier",
  "description": "Front counter cashier role",
  "employment_type": "part_time",
  "openings_count": 3,
  "status": "draft"
}
```

Validation:

- User must access `store_id`.
- Workflow must belong to the same store.

### Publish Job Opening

```http
POST /api/job-openings/{jobOpening}/publish
```

## Hiring Workflows

### List Workflows

```http
GET /api/workflows?store_id=1
```

### Create Workflow

```http
POST /api/workflows
```

Request:

```json
{
  "store_id": 1,
  "name": "Cashier Hiring Workflow"
}
```

### Add Stage

```http
POST /api/workflows/{workflow}/stages
```

Request:

```json
{
  "name": "Screening",
  "slug": "screening",
  "stage_type": "screening",
  "position": 2,
  "is_initial": false,
  "is_terminal": false,
  "auto_advance_enabled": true,
  "configuration": {}
}
```

### Create Transition

```http
POST /api/workflows/{workflow}/transitions
```

Request:

```json
{
  "from_stage_id": 1,
  "to_stage_id": 2,
  "name": "Applied to Screening",
  "is_manual_allowed": true,
  "is_automatic_allowed": true,
  "conditions": null
}
```

### Publish Workflow

```http
POST /api/workflows/{workflow}/publish
```

### Copy Workflow Configuration to Store

```http
POST /api/workflows/{workflow}/copy-to-store
```

Request:

```json
{
  "target_store_id": 2,
  "copy_questionnaires": true,
  "copy_documents": true,
  "copy_automation_rules": true
}
```

## Questionnaires

### Create Questionnaire Template

```http
POST /api/questionnaire-templates
```

Request:

```json
{
  "store_id": 1,
  "name": "Basic Screening Questionnaire"
}
```

### Add Question

```http
POST /api/questionnaire-templates/{template}/questions
```

Request:

```json
{
  "question_key": "work_authorization",
  "label": "Are you legally allowed to work?",
  "type": "boolean",
  "is_required": true,
  "position": 1,
  "options": null,
  "validation_rules": null,
  "visibility_rules": null
}
```

### Assign Questionnaire to Stage

```http
POST /api/workflow-stages/{stage}/questionnaires
```

Request:

```json
{
  "questionnaire_template_id": 1,
  "is_required": true
}
```

## Applicants and Applications

### Create Application

```http
POST /api/applications
```

Request:

```json
{
  "job_opening_id": 1,
  "applicant": {
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "phone": "+15555550123",
    "source": "career_page"
  }
}
```

Response:

```json
{
  "id": 10,
  "status": "active",
  "current_stage": {
    "id": 1,
    "name": "Applied",
    "slug": "applied"
  }
}
```

### List Applications

```http
GET /api/applications
```

Filters:

```text
store_id
job_opening_id
current_stage_id
status
search
```

### Show Application

```http
GET /api/applications/{application}
```

Include:

```text
applicant
job_opening
current_stage
answers
documents
activities
```

### Move Application Stage

```http
POST /api/applications/{application}/move-stage
```

Request:

```json
{
  "to_stage_id": 4,
  "reason": "Candidate passed interview"
}
```

## Applicant Answers

### Submit Answers

```http
POST /api/applications/{application}/answers
```

Request:

```json
{
  "questionnaire_template_id": 1,
  "answers": [
    {
      "question_id": 1,
      "answer": true
    },
    {
      "question_id": 2,
      "answer": "Weekends and evenings"
    }
  ]
}
```

## Documents

### Create Document Template

```http
POST /api/document-templates
```

Request:

```json
{
  "store_id": 1,
  "name": "ID Card",
  "document_type": "identity",
  "requires_signature": false,
  "description": "Government-issued ID"
}
```

### Assign Document to Stage

```http
POST /api/workflow-stages/{stage}/document-requirements
```

Request:

```json
{
  "document_template_id": 1,
  "is_required": true,
  "due_days_after_stage_entry": 3
}
```

### Submit Applicant Document

```http
POST /api/applications/{application}/documents/{applicantDocument}/submit
```

Request:

```json
{
  "file_path": "documents/applications/10/id-card.pdf"
}
```

### Approve Applicant Document

```http
POST /api/applications/{application}/documents/{applicantDocument}/approve
```

### Reject Applicant Document

```http
POST /api/applications/{application}/documents/{applicantDocument}/reject
```

Request:

```json
{
  "reason": "Document is unreadable"
}
```

## Automation Rules

### Create Rule

```http
POST /api/automation-rules
```

Request:

```json
{
  "store_id": 1,
  "hiring_workflow_id": 1,
  "workflow_stage_id": 2,
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
  "priority": 10,
  "is_active": true
}
```

## Activities

### List Application Activities

```http
GET /api/applications/{application}/activities
```

## Error Format

Recommended error format:

```json
{
  "message": "Validation failed",
  "errors": {
    "store_id": ["The selected store is invalid or not accessible."]
  }
}
```
