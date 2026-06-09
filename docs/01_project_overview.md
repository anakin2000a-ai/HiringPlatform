# 01 - Project Overview

## Objective

Build a Laravel backend API for a configurable hiring workflow platform used by franchise operators to manage applicants across multiple stores.

The system allows each store to define its own hiring process, applicant questions, required documents, workflow stages, and automation rules.

The backend must also integrate with a wider microservices environment using NATS events.

## Main Business Capabilities

The platform should support:

- Franchise account management
- Store management
- User authentication
- Store-scoped user access
- Job openings per store
- Configurable hiring workflows
- Custom workflow stages
- Allowed stage transitions
- Questionnaire templates
- Applicant answers
- Document requirements
- Applicant document tracking
- Automation rules
- Manual and automatic applicant stage movement
- Workflow activity history
- Configuration copy between stores
- Event-driven integration using NATS

## Main Actors

### Franchise Admin

Has access to the full franchise account and all stores.

Can manage:

- Stores
- Users
- Workflows
- Job openings
- Applicants
- Automation rules
- Configuration copy between stores

### Store Manager

Has access to one or more stores.

Can manage:

- Job openings for assigned stores
- Applicants for assigned stores
- Hiring workflows for assigned stores, depending on role policy
- Manual stage transitions
- Document approval

### Recruiter

Has operational access to applicants and hiring workflows for assigned stores.

Can manage:

- Applicants
- Stage movement
- Applicant notes/activity
- Document review, if allowed

### Viewer

Read-only access to assigned stores.

### Applicant

External candidate applying to a job opening.

In this backend assessment, applicant authentication is optional unless explicitly implemented. Applicant-facing operations can be modeled as public or token-based API endpoints.

## High-Level Workflow

```text
1. Franchise account is created.
2. Stores are created under the franchise account.
3. Users are created and assigned access to stores.
4. Store-specific hiring workflow is configured.
5. Workflow stages are created.
6. Allowed stage transitions are defined.
7. Questionnaire templates are created and assigned to workflow stages.
8. Document templates are created and assigned to workflow stages.
9. Automation rules are configured.
10. Job opening is created and linked to a workflow.
11. Applicant applies to the job opening.
12. Application starts at the initial workflow stage.
13. Applicant submits answers or documents.
14. Automation rules evaluate the application.
15. Application moves manually or automatically between stages.
16. Activities are logged.
17. Relevant events are stored in outbox and published to NATS.
```

## MVP Boundary

The minimum useful backend should include:

- Authentication
- Franchise/store/user access model
- CRUD for stores
- CRUD for job openings
- CRUD for workflows and stages
- Stage transition configuration
- Applicant creation and application creation
- Manual stage movement
- Questionnaire templates and answers
- Document requirements and applicant document status
- Automation rules with JSON conditions/actions
- Workflow activity history
- Outbox/inbox event tables
- Tests for the main business flows

## Not Required

The assessment does not require:

- Frontend
- Real digital signature provider integration
- Real email/SMS provider integration
- Complex RBAC tables
- Production NATS cluster
- Applicant login portal

These can be modeled in a way that supports future integration.
