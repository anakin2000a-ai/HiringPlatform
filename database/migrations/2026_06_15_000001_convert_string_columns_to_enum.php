<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts fixed-value string columns to MySQL ENUM type.
 *
 * Safety steps applied per column:
 *  1. Normalize any invalid/legacy values to a valid enum case before altering.
 *  2. Run ALTER TABLE ... MODIFY COLUMN with the enum list.
 *
 * No doctrine/dbal is required — uses raw SQL via DB::statement().
 *
 * Rollback restores each column to VARCHAR with the original length.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used in testing) does not support MODIFY COLUMN or MySQL ENUM.
        // Enum validation is still enforced at the PHP/model layer in tests.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // ── 1. Normalize known edge-cases before any column is altered ────────

        // hiring_workflows.status: 'published' was used by an old factory state;
        // the canonical value is 'active'. Map it before the ENUM is applied.
        DB::statement("UPDATE hiring_workflows SET status = 'active' WHERE status = 'published'");

        // user_store_access: normalize any rows whose role or access_scope are not
        // in the valid enum set (e.g. junk/test data). Safe default: viewer / store.
        DB::statement("
            UPDATE user_store_access
            SET role = 'viewer'
            WHERE role NOT IN ('franchise_admin','store_manager','recruiter','viewer')
        ");
        DB::statement("
            UPDATE user_store_access
            SET access_scope = 'store'
            WHERE access_scope NOT IN ('franchise','multi_store','store')
        ");
        DB::statement("
            UPDATE user_store_access
            SET status = 'active'
            WHERE status NOT IN ('active','inactive')
        ");

        // ── 2. franchise_accounts.status ─────────────────────────────────────
        DB::statement("
            ALTER TABLE franchise_accounts
            MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ");

        // ── 3. stores.status ─────────────────────────────────────────────────
        DB::statement("
            ALTER TABLE stores
            MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ");

        // ── 4. users.status ──────────────────────────────────────────────────
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ");

        // ── 5. user_store_access.role ────────────────────────────────────────
        DB::statement("
            ALTER TABLE user_store_access
            MODIFY COLUMN role ENUM('franchise_admin','store_manager','recruiter','viewer') NOT NULL DEFAULT 'viewer'
        ");

        // ── 6. user_store_access.access_scope ───────────────────────────────
        DB::statement("
            ALTER TABLE user_store_access
            MODIFY COLUMN access_scope ENUM('franchise','multi_store','store') NOT NULL DEFAULT 'store'
        ");

        // ── 7. user_store_access.status ──────────────────────────────────────
        DB::statement("
            ALTER TABLE user_store_access
            MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ");

        // ── 8. hiring_workflows.status ────────────────────────────────────────
        DB::statement("
            ALTER TABLE hiring_workflows
            MODIFY COLUMN status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft'
        ");

        // ── 9. workflow_stages.stage_type ─────────────────────────────────────
        DB::statement("
            ALTER TABLE workflow_stages
            MODIFY COLUMN stage_type ENUM('application','screening','interview','documents','approval','onboarding','hired','rejected','custom') NOT NULL
        ");

        // ── 10. job_openings.status ───────────────────────────────────────────
        DB::statement("
            ALTER TABLE job_openings
            MODIFY COLUMN status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft'
        ");

        // ── 11. job_openings.employment_type ──────────────────────────────────
        DB::statement("
            ALTER TABLE job_openings
            MODIFY COLUMN employment_type ENUM('full_time','part_time','contract','temporary') NULL DEFAULT NULL
        ");

        // ── 12. applications.status ───────────────────────────────────────────
        DB::statement("
            ALTER TABLE applications
            MODIFY COLUMN status ENUM('pending','active','hired','rejected','withdrawn') NOT NULL DEFAULT 'active'
        ");

        // ── 13. application_stage_transitions.transition_type ─────────────────
        DB::statement("
            ALTER TABLE application_stage_transitions
            MODIFY COLUMN transition_type ENUM('manual','automatic') NOT NULL DEFAULT 'manual'
        ");

        // ── 14. workflow_activities.actor_type ────────────────────────────────
        // event_type is intentionally left as VARCHAR (open-ended for extensibility).
        DB::statement("
            ALTER TABLE workflow_activities
            MODIFY COLUMN actor_type ENUM('user','automation') NULL DEFAULT NULL
        ");

        // ── 15. applicant_documents.status ────────────────────────────────────
        DB::statement("
            ALTER TABLE applicant_documents
            MODIFY COLUMN status ENUM('pending','submitted','signed','approved','rejected') NOT NULL DEFAULT 'pending'
        ");

        // ── 16. automation_rules.trigger ──────────────────────────────────────
        DB::statement("
            ALTER TABLE automation_rules
            MODIFY COLUMN `trigger` ENUM('application_created','stage_entered','answer_submitted','document_submitted','document_signed','document_approved','document_rejected') NOT NULL
        ");

        // ── 17. questionnaire_templates.status ────────────────────────────────
        DB::statement("
            ALTER TABLE questionnaire_templates
            MODIFY COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'
        ");

        // ── 18. questionnaire_questions.type ──────────────────────────────────
        DB::statement("
            ALTER TABLE questionnaire_questions
            MODIFY COLUMN `type` ENUM('text','number','boolean','date','select','multiselect') NOT NULL
        ");

        // ── 19. outbox_events.status ──────────────────────────────────────────
        // Normalize any 'publishing' intermediate value (should not exist in prod,
        // but guard against it).
        DB::statement("UPDATE outbox_events SET status = 'pending' WHERE status NOT IN ('pending','published','failed')");
        DB::statement("
            ALTER TABLE outbox_events
            MODIFY COLUMN status ENUM('pending','published','failed') NOT NULL DEFAULT 'pending'
        ");

        // ── 20. inbox_events.status ───────────────────────────────────────────
        DB::statement("UPDATE inbox_events SET status = 'pending' WHERE status NOT IN ('pending','processed','parked','failed')");
        DB::statement("
            ALTER TABLE inbox_events
            MODIFY COLUMN status ENUM('pending','processed','parked','failed') NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE franchise_accounts MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE stores MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE users MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE user_store_access MODIFY COLUMN role VARCHAR(100) NOT NULL DEFAULT 'viewer'");
        DB::statement("ALTER TABLE user_store_access MODIFY COLUMN access_scope VARCHAR(50) NOT NULL DEFAULT 'store'");
        DB::statement("ALTER TABLE user_store_access MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE hiring_workflows MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE workflow_stages MODIFY COLUMN stage_type VARCHAR(100) NOT NULL");
        DB::statement("ALTER TABLE job_openings MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE job_openings MODIFY COLUMN employment_type VARCHAR(100) NULL DEFAULT NULL");
        DB::statement("ALTER TABLE applications MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE application_stage_transitions MODIFY COLUMN transition_type VARCHAR(50) NOT NULL DEFAULT 'manual'");
        DB::statement("ALTER TABLE workflow_activities MODIFY COLUMN actor_type VARCHAR(100) NULL DEFAULT NULL");
        DB::statement("ALTER TABLE applicant_documents MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE automation_rules MODIFY COLUMN `trigger` VARCHAR(100) NOT NULL");
        DB::statement("ALTER TABLE questionnaire_templates MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE questionnaire_questions MODIFY COLUMN `type` VARCHAR(50) NOT NULL");
        DB::statement("ALTER TABLE outbox_events MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE inbox_events MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
    }
};
