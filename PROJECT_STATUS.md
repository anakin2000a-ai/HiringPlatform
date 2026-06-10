# Hiring Platform — Project Status

## Stack

- **Framework:** Laravel 13
- **PHP:** 8.3+
- **Database:** MySQL
- **Auth:** Laravel Sanctum
- **API:** REST only

---

## Completed Phases

### Phase 1 — Authentication
- Login / logout via Sanctum tokens
- User roles: `franchise_admin`, `store_manager`, `recruiter`

### Phase 2 — Franchise / Store / User Access
- `franchise_accounts`, `stores`, `user_store_access` tables
- `EnsureStoreAccess` middleware
- `StoreAccessService` for store-scoped authorization

### Phase 3 — Workflow Engine
- `hiring_workflows`, `workflow_stages`, `workflow_stage_transitions` tables
- Workflow CRUD, stage CRUD, transition CRUD
- `WorkflowService`, `WorkflowStageService`, `WorkflowTransitionValidator`

### Phase 4 — Job Openings
- `job_openings` table
- Publish / close lifecycle
- `JobOpeningService`

### Phase 5 — Applicants & Applications
- `applicants`, `applications` tables
- Public apply endpoint (no auth required)
- `ApplicantService`, `CreateApplicationService`, `ApplicationService`

### Phase 6 — Stage Movement & Activity History
- `application_stage_transitions`, `workflow_activities`, `outbox_events` tables
- Manual stage movement with transition validation
- `ApplicationStageService`, `WorkflowActivityService`
- Outbox events written for `hiring.application.stage_changed`

### Phase 7 — Questionnaires & Applicant Answers
- `questionnaire_templates`, `questionnaire_questions`, `stage_questionnaire_assignments`, `applicant_answers` tables
- Questionnaire template CRUD, question CRUD, stage assignment, answer submission
- `QuestionnaireService`, `QuestionnaireQuestionService`, `StageQuestionnaireAssignmentService`, `ApplicantAnswerService`
- Outbox events written for `hiring.questionnaire.submitted`

### Phase 11b — Outbound Hiring Events via NATS JetStream ✅

Company-style outbound NATS publish implementation for terminal application outcomes.

**Pattern ownership:**
- `ApplicationStageService` owns `private recordEvent(string $subject, array $data): void` — builds the `HiringEventFactory` envelope, calls `HiringOutboxService::record()`, then schedules `DB::afterCommit(fn() => PublishHiringOutboxEventJob::dispatch((string) $row->id))`; `DB::afterCommit` lives here, not in the outbox service
- `HiringEventFactory` — instance-based `make(string $type, array $data, ?Request $request = null, array $metaOverrides = []): array`; wraps any data array in a CloudEvents-shaped envelope; does not build model data
- `HiringOutboxService` — only records `OutboxEvent` rows via `record(string $subject, array $payload): OutboxEvent`; no dispatch, no `DB::afterCommit`
- `PublishHiringOutboxEventJob` — `$tries = 10`, non-readonly `string $outboxEventId`; increments `attempts`, calls `JetStreamPublisher::publish()`, sets `published_at`; `failed()` hook writes `last_error`; no try/catch in `handle()`
- `JetStreamPublisher` — `publish(string $subject, array $payload): array`; validates `nats.jetstream.enabled`; checks subject against `nats.jetstream.subjects` allowlist; calls `$client->getApi()->getStream($streamName)->put($subject, $json)`; returns normalized ACK array
- `ModelChangeSet` — ported for completeness under `App\Services\HiringEvents`

**Outbound subjects emitted:**
- `hiring.v1.application.hired` — when application moves to a `stage_type=hired` terminal stage for the first time
- `hiring.v1.application.rejected` — when application moves to a `stage_type=rejected` terminal stage for the first time
- Duplicate guard: event only emitted when `hired_at` / `rejected_at` was null before the move

**Envelope shape (CloudEvents 1.0):**
- Top-level: `specversion="1.0"`, `id` (ULID), `type`, `source="hiring-platform"`, `subject`, `time`, `datacontenttype="application/json"`, `data`, `meta`
- No top-level `version` key
- `meta` block: `correlation_id`, `causation_id`, `actor_user_id`, `actor_type`, `actor_ip`, `user_agent`

**`data` payload fields:**
`application_id`, `applicant_id`, `applicant_name`, `applicant_email`, `applicant_phone`, `job_opening_id`, `job_title`, `store_id`, `store_name` (from `stores.store_name`), `franchise_account_id`, `previous_stage_id`, `current_stage_id`, `current_stage_name`, `status`, `decision`, `decided_at`, `decided_by_user_id`, `applicant` (nested object with `first_name`, `last_name`, `email`, `phone`)

**NATS config (`config/nats.php`):**
- `nats.jetstream.stream` — env `NATS_HIRING_PLATFORM_STREAM`, default `HIRING_PLATFORM_EVENTS`
- `nats.jetstream.subjects` — `['hiring.v1.>']`
- `NATS_HIRING_STREAM` env key is not used anywhere
- Inbound `nats.streams` config and `JetStreamConsumer` were not modified

**Files added / changed:**
- `app/Services/HiringEvents/HiringEventFactory.php` — rewritten (instance make())
- `app/Services/HiringEvents/HiringOutboxService.php` — rewritten (record() only)
- `app/Services/HiringEvents/ModelChangeSet.php` — new
- `app/Jobs/PublishHiringOutboxEventJob.php` — rewritten (tries=10, string id, failed() hook)
- `app/Services/Nats/JetStreamPublisher.php` — rewritten (getApi/getStream/put, array return)
- `app/Services/Applications/ApplicationStageService.php` — added private recordEvent(), full data array
- `config/nats.php` — removed outbound_stream/outbound_stream_durable/publish.allowed_subjects; added jetstream.stream and jetstream.subjects
- `app/Services/Nats/JetStreamConsumer.php` — **unchanged**

---

### Phase 11 — Event Integration / Outbox-Inbox / NATS Adapter ✅
- `EventBusPublisher` interface with `LogEventBusPublisher` (default), `FakeEventBusPublisher` (tests), and `NatsEventBusPublisher` (stub)
- `OutboxPublisherService` — fetches pending outbox events (respecting `available_at`), publishes via `EventBusPublisher`, marks `published`; on failure applies exponential back-off (1/5/15/60 min) up to `maxAttempts` then marks `failed`
- Per-row `lockForUpdate()` inside individual transactions prevents concurrent workers from double-publishing
- `php artisan events:publish-outbox` — `--limit=50`, `--max-attempts=5`; outputs published/failed/retried counts
- `InboxEventProcessor` — idempotent event consumption keyed on `event_id`; inserts `inbox_events` row, dispatches to subject handler, marks `processed`; duplicate `processed` events are returned immediately; handler failures are recorded and re-thrown
- Supported inbox subjects: `auth.v1.user.created/updated/deleted`, `auth.v1.store.created/updated/deleted`, `auth.v1.assignment.user_role_store.assigned/removed/toggled/bulk_assigned`, legacy subjects (`stores.store.*`, `users.user.*`, `applicants.applicant.*`), unknown subjects (safe no-op)
- Assignment event handlers create/delete `user_store_access` rows; `user_store_access` has no `is_active`/`status`/`role` columns — row presence represents active access; `assigned`/`toggled-true` create the row, `removed`/`toggled-false` delete it; `bulk_assigned` validates all users and stores atomically before any write
- Outbox extended with `available_at` (backoff scheduling) and `last_error` columns
- New `inbox_events` table with idempotency index on `event_id`
- `EventBusPublisher::class` bound to `LogEventBusPublisher::class` in `AppServiceProvider`
- **NATS transport package:** `basis-company/nats` v1.2.1 (`Basis\Nats` namespace)
- **`NatsClientFactory`** — `App\Services\Nats`; matches company-style factory exactly; direct `use Basis\Nats\Client` / `Configuration` imports; `make(): Client`; validates host/port and requires token or user+pass auth
- **`JetStreamConsumer`** — `App\Services\Nats`; ported to company-style structure with only project-required adaptations; key behaviors:
  - Single `Basis\Nats\Client` instance created once and reused for the lifetime of the process
  - Consumer objects cached per `stream|durable` key via `$consumerCache` — no repeated `create()` calls
  - Stream/durable config consumed from `config('nats.streams')` array
  - `runForever()` — outer loop with per-stream `consumeStream()` calls and `ERROR_BACKOFF_MS` guard on outer errors
  - `consume(array $streamConfig, ?int $batch): int` — public one-shot method for `ConsumeNatsEventsCommand --once`
  - `consumeStream()` / `getOrInitConsumer()` — private; subject-filter set best-effort via `getConfiguration()->setSubjectFilter()`
  - `handleMessage()` — private; validates `$JS.ACK.` reply prefix (real JetStream deliveries only); domain allowlist check (`auth.v1.` prefix); Shape A (`event_id/event_type/data`) and Shape B (`id/subject/payload`) extraction; direct `$msg->ack()` / `$msg->nack($delay)` / `$msg->term()` calls via `ackOrTermSafe()` / `nackWithDelaySafe()` helpers
  - Uses `App\Models\InboxEvent` — `EventInbox` never referenced
  - Does not write non-existent `inbox_events` columns: `source`, `stream`, `consumer`, `parked_at`
  - Idempotency via `status`: `processed` → ACK/skip; `parked` → ACK/skip; new → insert then dispatch
  - On success: `status=processed`, `processed_at=now()`, `failed_at=null`, `last_error=null`
  - On handler failure below `MAX_PROCESSING_ATTEMPTS=5`: increment `attempts`, save `last_error`, NACK with `NACK_DELAY_SECONDS=2` delay
  - On max attempts: `status=parked`, `failed_at=now()`, ACK/TERM — event never retried again
  - Handlers receive the inner `data` array (`$event['data'] ?? $event['payload'] ?? []`), not the full event object
- **`EventRouter::resolve(string $subject): string`** — instance method; returns handler class for known subjects or `NoOpHandler::class` for unknown; used by `JetStreamConsumer::handleMessage()`
- **`EventRouter::routeNatsPayload(string $subject, array $payload): InboxEvent`** — entry point from NATS transport; normalizes raw decoded payload via `normalizePayload()` then delegates to `InboxEventProcessor::process()`
- **`EventRouter::normalizePayload(string $subject, array $decoded): array`** — public static; owns Shape A (`event_id/event_type/data`) and Shape B (`id/subject/payload`) normalization; throws `InvalidArgumentException` on missing identity or non-array data field
- **Layer responsibilities:** `JetStreamConsumer` = transport + inline idempotency → `EventRouter::resolve()` = handler lookup → handler = business sync
- Services: `OutboxPublisherService`, `InboxEventProcessor`; transport: `App\Services\Nats\NatsClientFactory`, `App\Services\Nats\JetStreamConsumer`
- Files: `app/Services/Events/EventBusPublisher.php`, `LogEventBusPublisher.php`, `FakeEventBusPublisher.php`, `NatsEventBusPublisher.php`, `OutboxPublisherService.php`, `InboxEventProcessor.php`, `EventRouter.php`; `app/Services/Nats/NatsClientFactory.php`, `JetStreamConsumer.php`; handlers: `app/Services/Events/Handlers/UserRoleStoreAssignedHandler.php`, `UserRoleStoreRemovedHandler.php`, `UserRoleStoreToggledHandler.php`, `UserRoleStoreBulkAssignedHandler.php`
- Models: `InboxEvent`; `OutboxEvent` updated with `available_at`/`last_error`

### Phase 10 — Configuration Copy Between Stores ✅
- Copy hiring configuration from one source store to one target store as independent target-store-owned records
- Endpoint: `POST /api/v1/stores/{store}/copy-configuration`
- Copy flags (all default `true`): `copy_workflows`, `copy_questionnaires`, `copy_documents`, `copy_automation_rules`
- Copies: `hiring_workflows`, `workflow_stages`, `workflow_stage_transitions`, `questionnaire_templates`, `questionnaire_questions`, `stage_questionnaire_assignments`, `document_templates`, `stage_document_requirements`, `automation_rules`
- Does not copy: users, user_store_access, job_openings, applicants, applications, applicant_answers, applicant_documents, application_stage_transitions, workflow_activities, outbox_events
- Full ID remapping: source workflow/stage/questionnaire_template/document_template IDs are never written into the target store; all cross-references use newly assigned target IDs
- Duplicate workflow or questionnaire template names resolved deterministically: original → `"{name} (Copy from {source store_name})"` → numeric suffix
- `copy_questionnaires=true` + `copy_workflows=false`: templates and questions copied, stage assignments skipped; skipped count recorded in metadata
- `copy_documents=true` + `copy_workflows=false`: templates copied, stage requirements skipped; skipped count recorded in metadata
- `copy_automation_rules=true` + `copy_workflows=false`: only store-level rules (no workflow/stage scope) copied; scoped rules skipped and counted in metadata
- Edge case: if a scoped automation rule cannot be remapped (source IDs not in map), it falls back to null scope; this does not occur on normal full-copy paths
- Authorization: source store via `EnsureStoreAccess` middleware; target store via `StoreAccessService::canAccessStore()` in Form Request; role check (`franchise_admin` or `store_manager`) in controller
- Entire operation runs in a single `DB::transaction`; failure at any step rolls back all inserts
- `configuration_copy_logs` row written as the last step inside the transaction
- Services: `ConfigurationCopyService`
- Routes: `stores/{store}/copy-configuration` (POST, inside `store.access` middleware group)

### Phase 9 — Automation Rules ✅
- `automation_rules` table with store scope, optional workflow/stage scope, trigger, conditions (JSON), actions (JSON), priority, is_active
- Supported triggers: `application_created`, `stage_entered`, `answer_submitted`, `document_submitted`, `document_signed`, `document_approved`, `document_rejected`
- Condition evaluation: `all` / `any` groups; 14 operators; field paths covering `applicant.*`, `application.*`, `current_stage.*`, `answers.{key}`, `documents.{type}.status`, `documents.all_required.status`
- Actions: `move_to_stage`, `reject_application`, `mark_hired`, `create_activity`, `publish_event`, `set_score`, `increment_score`
- `move_to_stage` and terminal actions (`reject_application`, `mark_hired`) all go through `ApplicationStageService::move()` — `applications.current_stage_id` is never updated directly by the automation layer
- `move_to_stage` uses `transitionType='automatic'`, validated against `workflow_stage_transitions.is_automatic_allowed`; disallowed transitions are caught and logged as `automation_action_failed` rather than thrown
- Loop prevention: executed rule IDs accumulated across the call chain; any rule already in the list is skipped; hard depth cap at `MAX_DEPTH = 5`
- `stage_slug` in the automation action payload maps to `workflow_stages.name` — `workflow_stages` has no slug column in the current schema
- `current_stage.slug` in condition field paths also maps to `workflow_stages.name` for the same reason
- Engine injected into `CreateApplicationService`, `ApplicationController::moveStage`, `ApplicantAnswerService`, `ApplicantDocumentService`; all `evaluate()` calls happen after their `DB::transaction` commits
- Services: `AutomationRuleEngine`, `RuleConditionEvaluator`, `RuleActionExecutor`, `AutomationRuleService`
- Routes: `stores/{store}/automation-rules` (index/create); `automation-rules/{automationRule}` (show/update/destroy)

### Phase 8 — Documents ✅
- `document_templates`, `stage_document_requirements`, `applicant_documents` tables
- Document template CRUD (store-scoped under `stores/{store}/document-templates`)
- Stage document requirement CRUD (store resolved through `stage → workflow → store`)
- Applicant document lifecycle: initiate → submit → sign → approve / reject
- Signature-required path: `submitted → signed → approved`
- No-signature path: `submitted → approved`
- Rejected documents can be resubmitted
- Approved documents cannot be resubmitted, signed, approved again, or rejected
- Activity records written for: `applicant_document_submitted`, `applicant_document_signed`, `applicant_document_approved`, `applicant_document_rejected`
- Outbox events written for: `hiring.document.submitted`, `hiring.document.signed`, `hiring.document.approved`, `hiring.document.rejected`
- Services: `DocumentTemplateService`, `StageDocumentRequirementService`, `ApplicantDocumentService`

---

## Database Tables

| Table | Phase |
|---|---|
| `users` | 1 |
| `franchise_accounts` | 2 |
| `stores` | 2 |
| `user_store_access` | 2 |
| `hiring_workflows` | 3 |
| `workflow_stages` | 3 |
| `workflow_stage_transitions` | 3 |
| `job_openings` | 4 |
| `applicants` | 5 |
| `applications` | 5 |
| `application_stage_transitions` | 6 |
| `workflow_activities` | 6 |
| `outbox_events` | 6 |
| `questionnaire_templates` | 7 |
| `questionnaire_questions` | 7 |
| `stage_questionnaire_assignments` | 7 |
| `applicant_answers` | 7 |
| `document_templates` | 8 |
| `stage_document_requirements` | 8 |
| `applicant_documents` | 8 |
| `automation_rules` | 9 |
| `configuration_copy_logs` | 10 |
| `inbox_events` | 11 |

---

## Test Suite

- **Tests:** 480 passing
- **Assertions:** 1050 passing
- **NatsClientTest:** 32 passing
- **JetStreamConsumerTest:** 8 passing
- **AssignmentEventTest:** 20 passing
- **NatsAlignmentTest:** 10 passing (inbound + outbound alignment)
- **ApplicationStageServiceOutboundTest:** 15 passing
- **HiringEventFactoryTest:** 14 passing
- **HiringOutboxServiceTest:** 17 passing (includes HiringOutbox filter matches)
- **JetStreamPublisherTest:** 12 passing
- **SafetyTest:** 11 passing
- **Runner:** PHPUnit via `php artisan test`

---

## Migration Readiness

- `php artisan migrate:fresh` passes cleanly on MySQL — 30 migrations, no errors
- All MySQL 64-character identifier violations have been fixed with explicit short names:

| Old auto-generated name | New explicit name |
|---|---|
| `workflow_stage_transitions_hiring_workflow_id_from_stage_id_to_stage_id_index` (78) | `wst_workflow_from_to_idx` |
| `questionnaire_questions_questionnaire_template_id_question_key_unique` (69) | `qq_template_question_key_unique` |
| `stage_questionnaire_assignments_questionnaire_template_id_foreign` (65) | `sqa_template_fk` |
| `stage_questionnaire_assignments_workflow_stage_id_questionnaire_template_id_unique` (82) | `sqa_stage_template_unique` |
| `applicant_answers_application_id_questionnaire_question_id_unique` (65) | `aa_app_question_unique` |
| `stage_document_requirements_workflow_stage_id_document_template_id_unique` (73) | `sdr_stage_document_unique` |

---

## Architecture Decisions

- **No Laravel Policies** — no Policy classes, no `Gate::authorize()`, no `$this->authorize()`
- **Authorization** — handled exclusively through `EnsureStoreAccess` middleware + `StoreAccessService`
- **Store scope** — resolved by `store_name` per project convention; foreign keys still use `store_id` internally
- **Controllers** — thin; business logic lives in services/actions
- **Validation** — Form Request classes
- **Responses** — API Resource classes + `ApiResponse` helper
- **Multi-step operations** — wrapped in DB transactions
- **Outbox** — events written to `outbox_events` table only; no direct NATS publishing
- **Automation** — `AutomationRuleEngine` evaluates rules after transactions commit; circular DI avoided by passing engine as parameter to `RuleActionExecutor::executeAll()`
- **Automation stage moves** — `move_to_stage`, `reject_application`, and `mark_hired` all delegate to `ApplicationStageService`; the automation layer never writes `applications.current_stage_id` directly
- **Automation recursion guard** — executed rule IDs accumulated across the full call chain prevent re-firing; hard cap at `AutomationRuleEngine::MAX_DEPTH = 5` prevents infinite stage-entered loops
- **`stage_slug` mapping** — the `stage_slug` key in automation action payloads maps to `workflow_stages.name`; there is no `slug` column on `workflow_stages` in the current schema. Likewise, `current_stage.slug` in condition field paths resolves to `workflow_stages.name`. Because stage names are preserved verbatim during configuration copy, no JSON rewriting is needed for automation condition/action blobs
- **Configuration copy transaction** — the entire `ConfigurationCopyService::copy()` operation runs inside a single `DB::transaction`; if any insert fails, no partial records are left in the target store and no `configuration_copy_logs` row is written
- **Configuration copy ID remapping** — source workflow, stage, questionnaire template, document template, and automation rule scope IDs are all remapped to newly created target IDs using in-memory maps built during the transaction; copied rows never reference source-store-owned IDs
- **Configuration copy independence** — copied records are fully independent from the source store; modifying the target configuration does not affect the source
- **Configuration copy automation edge case** — if `copy_automation_rules=true` and `copy_workflows=false`, only store-level rules (both `hiring_workflow_id` and `workflow_stage_id` null) are copied; workflow/stage-scoped rules are skipped and counted in `metadata.skipped.automation_rules`. On full-copy paths, all scoped IDs remap successfully. If a scoped rule were somehow not resolvable, the implementation falls back to null scope rather than failing.
- **Outbound NATS publish pattern** — `ApplicationStageService::recordEvent()` owns `DB::afterCommit` and `PublishHiringOutboxEventJob` dispatch; `HiringOutboxService::record()` only persists; `HiringEventFactory::make()` only wraps; `JetStreamPublisher` uses `getApi()->getStream()->put()` (company-style); job uses `failed()` hook not try/catch; no `Auth` outbox table; `NATS_HIRING_PLATFORM_STREAM` used, not `NATS_HIRING_STREAM`
- **CloudEvents envelope** — `specversion="1.0"`, ULID `id`, no top-level `version` key; `meta` block carries `correlation_id`, `actor_type`, `actor_user_id`, `actor_ip`, `user_agent`; `data` carries all decision fields including `decision`, `status`, `store_name` from `stores.store_name`, and nested `applicant` object
- **NATS transport layer** — `JetStreamConsumer` is ported to company-style structure; it owns inline idempotency directly via `InboxEvent`; subject allowlist (`auth.v1.` prefix) and `$JS.ACK.` reply guard prevent phantom message processing; single `Client` instance reused for process lifetime; consumer objects cached per `stream|durable`
- **NATS idempotency** — `JetStreamConsumer::handleMessage()` manages `InboxEvent` rows directly inside a `DB::transaction`; `status=processed` → ACK/skip; `status=parked` → ACK/skip; new events → insert then dispatch; `MAX_PROCESSING_ATTEMPTS=5` failures → `status=parked, failed_at=now()`; success → `status=processed, processed_at=now(), failed_at=null, last_error=null`
- **NATS handler dispatch** — `EventRouter::resolve(string $subject): string` returns the handler class (or `NoOpHandler` for unknown subjects); handlers receive the inner `data` array extracted from the event, not the full raw payload; Shape A (`event_id/event_type/data`) and Shape B (`id/subject/payload`) both supported
- **NATS payload normalization** — `EventRouter::normalizePayload()` owns Shape A/B normalization; `EventRouter::routeNatsPayload()` remains available for direct envelope routing; `EventRouter::resolve()` is used by `JetStreamConsumer` for handler lookup
- **`user_store_access` schema** — the table has only `user_id`, `store_id`, and timestamps; no `role`, `metadata`, `is_active`, or `status` columns; row presence = active access; assignment event handlers never mass-assign arbitrary payload fields

---

## Next Phase

### Phase 12 — Final Documentation / README / API Docs

- Do not implement until Phase 12 is explicitly authorized
