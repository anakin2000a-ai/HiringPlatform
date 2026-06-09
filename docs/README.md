# Store-Based Hiring Workflow Platform - Backend Assessment

This documentation package is intended to guide the implementation of a Laravel backend API for a configurable store-based hiring workflow platform.

The system supports franchise accounts, multiple stores, users with scoped access, configurable hiring workflows, workflow stages, questionnaire templates, applicant answers, required documents, automation rules, workflow activity history, and event-driven integration through NATS.

## Recommended Reading Order

1. `01_project_overview.md`
2. `02_domain_model.md`
3. `03_database_schema_dbml.md`
4. `04_workflow_design.md`
5. `05_api_design.md`
6. `06_laravel_architecture.md`
7. `07_automation_rules.md`
8. `08_nats_event_integration.md`
9. `09_testing_plan.md`
10. `10_implementation_phases_for_claude.md`
11. `11_assumptions_and_tradeoffs.md`

## Implementation Target

- Framework: Laravel
- Database: MySQL
- API style: RESTful JSON API
- Auth: Laravel Sanctum recommended
- Queue: Laravel Queue
- Events: Outbox / Inbox pattern for NATS integration
- Testing: PHPUnit / Pest

## Core Design Principle

The platform is not only an applicant tracker. It is a configurable workflow engine scoped by franchise stores.

The core relationship is:

```text
Franchise Account
  -> Stores
    -> Job Openings
      -> Hiring Workflow
        -> Workflow Stages
          -> Stage Transitions
          -> Questionnaires
          -> Documents
          -> Automation Rules
            -> Applicant Applications move through the workflow
```
