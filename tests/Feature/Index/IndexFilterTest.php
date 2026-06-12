<?php

namespace Tests\Feature\Index;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Application;
use App\Models\AutomationRule;
use App\Models\DocumentTemplate;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowActivity;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexFilterTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function makeAdminStore(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();
        $admin     = User::factory()->create();
        UserStoreAccess::create([
            'user_id'      => $admin->id,
            'store_id'     => $store->id,
            'role'         => 'franchise_admin',
            'access_scope' => 'franchise',
            'status'       => 'active',
        ]);
        return [$franchise, $store, $admin];
    }

    private function makeWorkflowWithStages(Store $store, int $count = 3): array
    {
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $stages   = [];
        for ($i = 1; $i <= $count; $i++) {
            $stages[] = WorkflowStage::factory()->forWorkflow($workflow)->create([
                'position'   => $count - $i + 1, // insert in reverse so order matters
                'is_initial' => $i === $count,
            ]);
        }
        return [$workflow, $stages];
    }

    private function makePublishedJob(Store $store, HiringWorkflow $workflow): JobOpening
    {
        return JobOpening::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $workflow->id,
            'status'             => 'published',
        ]);
    }

    private function makeApplication(JobOpening $job, ?WorkflowStage $stage = null): Application
    {
        return Application::factory()->create([
            'job_opening_id'  => $job->id,
            'current_stage_id'=> $stage?->id,
        ]);
    }

    // =========================================================================
    // per_page validation
    // =========================================================================

    public function test_per_page_defaults_to_20_on_stores_index(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $response = $this->actingAs($admin)->getJson('/api/v1/stores');

        $response->assertOk();
        $this->assertArrayHasKey('per_page', $response->json('data.meta'));
        $this->assertEquals(20, $response->json('data.meta.per_page'));
    }

    public function test_per_page_parameter_is_honoured_on_workflows_index(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        HiringWorkflow::factory()->count(5)->forStore($store)->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows?per_page=2");

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.meta.per_page'));
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_per_page_is_capped_at_100_and_returns_422_when_above(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows?per_page=101")
            ->assertUnprocessable();
    }

    public function test_per_page_returns_422_when_not_integer(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows?per_page=abc")
            ->assertUnprocessable();
    }

    public function test_per_page_100_is_accepted_on_job_openings(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        JobOpening::factory()->count(3)->create(['store_id' => $store->id, 'hiring_workflow_id' => $workflow->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/job-openings?per_page=100")
            ->assertOk();
    }

    // =========================================================================
    // invalid status filters return 422
    // =========================================================================

    public function test_invalid_status_on_job_openings_returns_422(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/job-openings?status=invalid_status")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('status');
    }

    public function test_valid_statuses_on_job_openings_are_accepted(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        JobOpening::factory()->create(['store_id' => $store->id, 'hiring_workflow_id' => $workflow->id, 'status' => 'draft']);
        JobOpening::factory()->create(['store_id' => $store->id, 'hiring_workflow_id' => $workflow->id, 'status' => 'published']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/job-openings?status=draft");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('draft', $response->json('data.data.0.status'));
    }

    public function test_invalid_status_on_applications_returns_422(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?status=not_a_status")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('status');
    }

    public function test_valid_status_filter_on_applications_returns_matching_records(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job      = $this->makePublishedJob($store, $workflow);

        Application::factory()->create(['job_opening_id' => $job->id, 'status' => 'active']);
        Application::factory()->create(['job_opening_id' => $job->id, 'status' => 'hired']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?status=hired");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('hired', $response->json('data.data.0.status'));
    }

    public function test_invalid_status_on_applicant_documents_returns_422(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow  = HiringWorkflow::factory()->forStore($store)->create();
        $job       = $this->makePublishedJob($store, $workflow);
        $app       = $this->makeApplication($job);

        $this->actingAs($admin)
            ->getJson("/api/v1/applications/{$app->id}/documents?status=bad_status")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('status');
    }

    // =========================================================================
    // job_opening_id from another store is rejected
    // =========================================================================

    public function test_job_opening_id_from_another_store_returns_422(): void
    {
        [$franchise, $store, $admin]         = $this->makeAdminStore();
        [$franchise2, $otherStore, $admin2] = $this->makeAdminStore();

        $workflow2  = HiringWorkflow::factory()->forStore($otherStore)->create();
        $otherJob   = JobOpening::factory()->create([
            'store_id'           => $otherStore->id,
            'hiring_workflow_id' => $workflow2->id,
        ]);

        // admin tries to filter store's applications using a job opening from another store
        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?job_opening_id={$otherJob->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('job_opening_id');
    }

    public function test_job_opening_id_from_same_store_is_accepted(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job      = $this->makePublishedJob($store, $workflow);

        Application::factory()->create(['job_opening_id' => $job->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?job_opening_id={$job->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    // =========================================================================
    // current_stage_id from another store is rejected
    // =========================================================================

    public function test_current_stage_id_from_another_store_returns_422(): void
    {
        [$franchise, $store, $admin]         = $this->makeAdminStore();
        [$franchise2, $otherStore, $admin2] = $this->makeAdminStore();

        $workflow2   = HiringWorkflow::factory()->forStore($otherStore)->create();
        $otherStage  = WorkflowStage::factory()->forWorkflow($workflow2)->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?current_stage_id={$otherStage->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('current_stage_id');
    }

    public function test_current_stage_id_from_same_store_is_accepted(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        [$workflow, $stages] = $this->makeWorkflowWithStages($store, 1);
        $stage = $stages[0];
        $job   = $this->makePublishedJob($store, $workflow);
        $this->makeApplication($job, $stage);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?current_stage_id={$stage->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    // =========================================================================
    // Deterministic ordering — workflow stages ordered by position
    // =========================================================================

    public function test_workflow_stages_are_returned_in_position_order(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        // Insert in reverse position order deliberately
        WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 3, 'name' => 'Third']);
        WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1, 'name' => 'First', 'is_initial' => true]);
        WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2, 'name' => 'Second']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages");

        $response->assertOk();
        $positions = array_column($response->json('data.data'), 'position');
        $this->assertEquals([1, 2, 3], $positions);
    }

    // =========================================================================
    // Deterministic ordering — questionnaire questions ordered by position
    // =========================================================================

    public function test_questionnaire_questions_are_returned_in_position_order(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $questionnaire = QuestionnaireTemplate::factory()->create(['store_id' => $store->id]);

        // Insert in reverse order
        QuestionnaireQuestion::factory()->create(['questionnaire_template_id' => $questionnaire->id, 'position' => 3, 'question_key' => 'q3', 'label' => 'Q3']);
        QuestionnaireQuestion::factory()->create(['questionnaire_template_id' => $questionnaire->id, 'position' => 1, 'question_key' => 'q1', 'label' => 'Q1']);
        QuestionnaireQuestion::factory()->create(['questionnaire_template_id' => $questionnaire->id, 'position' => 2, 'question_key' => 'q2', 'label' => 'Q2']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions");

        $response->assertOk();
        $positions = array_column($response->json('data.data'), 'position');
        $this->assertEquals([1, 2, 3], $positions);
    }

    // =========================================================================
    // Applicant documents — filter by status
    // =========================================================================

    public function test_applicant_documents_can_filter_by_status(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job      = $this->makePublishedJob($store, $workflow);
        $app      = $this->makeApplication($job);
        $template = DocumentTemplate::factory()->create(['store_id' => $store->id]);

        ApplicantDocument::factory()->create([
            'application_id'      => $app->id,
            'document_template_id'=> $template->id,
            'status'              => 'pending',
        ]);
        ApplicantDocument::factory()->create([
            'application_id'      => $app->id,
            'document_template_id'=> $template->id,
            'status'              => 'approved',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/applications/{$app->id}/documents?status=approved");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('approved', $response->json('data.data.0.status'));
    }

    public function test_applicant_documents_are_returned_newest_first(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job      = $this->makePublishedJob($store, $workflow);
        $app      = $this->makeApplication($job);
        $template = DocumentTemplate::factory()->create(['store_id' => $store->id]);

        $older = ApplicantDocument::factory()->create([
            'application_id'      => $app->id,
            'document_template_id'=> $template->id,
            'created_at'          => now()->subHour(),
        ]);
        $newer = ApplicantDocument::factory()->create([
            'application_id'      => $app->id,
            'document_template_id'=> $template->id,
            'created_at'          => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/applications/{$app->id}/documents");

        $response->assertOk();
        $ids = array_column($response->json('data.data'), 'id');
        $this->assertEquals([$newer->id, $older->id], $ids);
    }

    // =========================================================================
    // Automation rules — filter by trigger and is_active
    // =========================================================================

    public function test_automation_rules_can_filter_by_trigger(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        AutomationRule::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $workflow->id,
            'trigger'            => 'application_created',
        ]);
        AutomationRule::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $workflow->id,
            'trigger'            => 'stage_entered',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/automation-rules?trigger=stage_entered");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('stage_entered', $response->json('data.data.0.trigger'));
    }

    public function test_automation_rules_can_filter_by_is_active(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        AutomationRule::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $workflow->id,
            'is_active'          => true,
        ]);
        AutomationRule::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $workflow->id,
            'is_active'          => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/automation-rules?is_active=0");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertFalse($response->json('data.data.0.is_active'));
    }

    // =========================================================================
    // Store search filter
    // =========================================================================

    public function test_stores_index_can_search_by_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin     = User::factory()->create();
        $storeA    = Store::factory()->for($franchise)->create(['store_name' => 'Alpha Store']);
        $storeB    = Store::factory()->for($franchise)->create(['store_name' => 'Beta Store']);
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeB->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/api/v1/stores?search=Alpha');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Alpha Store', $response->json('data.data.0.store_name'));
    }

    // =========================================================================
    // Job opening search by title
    // =========================================================================

    public function test_job_openings_index_can_search_by_title(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        JobOpening::factory()->create(['store_id' => $store->id, 'hiring_workflow_id' => $workflow->id, 'title' => 'Cashier']);
        JobOpening::factory()->create(['store_id' => $store->id, 'hiring_workflow_id' => $workflow->id, 'title' => 'Barista']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/job-openings?search=Cash");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Cashier', $response->json('data.data.0.title'));
    }

    // =========================================================================
    // Applications search by applicant name
    // =========================================================================

    public function test_applications_can_search_by_applicant_name(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job      = $this->makePublishedJob($store, $workflow);

        $applicantJohn = Applicant::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com']);
        $applicantJane = Applicant::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com']);

        Application::factory()->create(['job_opening_id' => $job->id, 'applicant_id' => $applicantJohn->id]);
        Application::factory()->create(['job_opening_id' => $job->id, 'applicant_id' => $applicantJane->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?search=John");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    // =========================================================================
    // Workflow stage filters
    // =========================================================================

    public function test_workflow_stages_can_filter_by_stage_type(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        WorkflowStage::factory()->forWorkflow($workflow)->create(['stage_type' => 'standard', 'position' => 1, 'is_initial' => true]);
        WorkflowStage::factory()->forWorkflow($workflow)->create(['stage_type' => 'review', 'position' => 2]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages?stage_type=review");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('review', $response->json('data.data.0.stage_type'));
    }

    public function test_workflow_stages_can_filter_by_is_initial(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        WorkflowStage::factory()->forWorkflow($workflow)->create(['is_initial' => true, 'position' => 1]);
        WorkflowStage::factory()->forWorkflow($workflow)->create(['is_initial' => false, 'position' => 2]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages?is_initial=1");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertTrue($response->json('data.data.0.is_initial'));
    }

    // =========================================================================
    // Workflow transition filters
    // =========================================================================

    public function test_workflow_transitions_can_filter_by_to_stage_id(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $stageA = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1, 'is_initial' => true]);
        $stageB = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);
        $stageC = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 3]);

        WorkflowStageTransition::factory()->create(['hiring_workflow_id' => $workflow->id, 'from_stage_id' => $stageA->id, 'to_stage_id' => $stageB->id]);
        WorkflowStageTransition::factory()->create(['hiring_workflow_id' => $workflow->id, 'from_stage_id' => $stageA->id, 'to_stage_id' => $stageC->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/transitions?to_stage_id={$stageB->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals($stageB->id, $response->json('data.data.0.to_stage_id'));
    }

    // =========================================================================
    // Document template filters
    // =========================================================================

    public function test_document_templates_can_filter_by_document_type(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        DocumentTemplate::factory()->create(['store_id' => $store->id, 'document_type' => 'id_verification']);
        DocumentTemplate::factory()->create(['store_id' => $store->id, 'document_type' => 'nda']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/document-templates?document_type=nda");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('nda', $response->json('data.data.0.document_type'));
    }

    public function test_document_templates_can_filter_by_requires_signature(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        DocumentTemplate::factory()->create(['store_id' => $store->id, 'requires_signature' => true]);
        DocumentTemplate::factory()->create(['store_id' => $store->id, 'requires_signature' => false]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/document-templates?requires_signature=1");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertTrue($response->json('data.data.0.requires_signature'));
    }

    // =========================================================================
    // Questionnaire filters
    // =========================================================================

    public function test_questionnaires_can_filter_by_status(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        QuestionnaireTemplate::factory()->create(['store_id' => $store->id, 'status' => 'active']);
        QuestionnaireTemplate::factory()->create(['store_id' => $store->id, 'status' => 'inactive']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires?status=active");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('active', $response->json('data.data.0.status'));
    }

    public function test_questionnaire_questions_can_filter_by_type(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $questionnaire = QuestionnaireTemplate::factory()->create(['store_id' => $store->id]);

        QuestionnaireQuestion::factory()->create(['questionnaire_template_id' => $questionnaire->id, 'type' => 'text', 'position' => 1, 'question_key' => 'q1', 'label' => 'Q1']);
        QuestionnaireQuestion::factory()->create(['questionnaire_template_id' => $questionnaire->id, 'type' => 'boolean', 'position' => 2, 'question_key' => 'q2', 'label' => 'Q2']);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions?type=boolean");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('boolean', $response->json('data.data.0.type'));
    }

    // =========================================================================
    // Cross-store scoping — unauthorized access still blocked
    // =========================================================================

    public function test_user_with_no_store_access_cannot_see_another_stores_workflows(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        HiringWorkflow::factory()->forStore($store)->create();

        [$franchise2, $store2, $admin2] = $this->makeAdminStore();

        // admin2 tries to access store (not store2)
        $this->actingAs($admin2)
            ->getJson("/api/v1/stores/{$store->id}/workflows")
            ->assertForbidden();
    }

    public function test_store_filter_in_user_index_scoped_to_franchise(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $otherFranchise = FranchiseAccount::factory()->create();
        $otherStore     = Store::factory()->for($otherFranchise)->create();
        $outsider       = User::factory()->create();
        UserStoreAccess::create(['user_id' => $outsider->id, 'store_id' => $otherStore->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);

        // Requesting store_id from another franchise should return empty, not the other franchise's users
        $response = $this->actingAs($admin)
            ->getJson("/api/v1/users?store_id={$otherStore->id}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    // =========================================================================
    // pagination on previously unpaginated endpoints
    // =========================================================================

    public function test_workflow_stages_now_returns_paginated_response(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        for ($i = 1; $i <= 5; $i++) {
            WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => $i, 'is_initial' => $i === 1]);
        }

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(5, $response->json('data.meta.total'));
    }

    public function test_workflow_transitions_now_returns_paginated_response(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $stageA = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1, 'is_initial' => true]);
        $stageB = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);
        $stageC = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 3]);

        WorkflowStageTransition::factory()->create(['hiring_workflow_id' => $workflow->id, 'from_stage_id' => $stageA->id, 'to_stage_id' => $stageB->id]);
        WorkflowStageTransition::factory()->create(['hiring_workflow_id' => $workflow->id, 'from_stage_id' => $stageB->id, 'to_stage_id' => $stageC->id]);
        WorkflowStageTransition::factory()->create(['hiring_workflow_id' => $workflow->id, 'from_stage_id' => $stageA->id, 'to_stage_id' => $stageC->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/transitions?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(3, $response->json('data.meta.total'));
    }

    public function test_questionnaire_questions_now_returns_paginated_response(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $questionnaire = QuestionnaireTemplate::factory()->create(['store_id' => $store->id]);

        for ($i = 1; $i <= 4; $i++) {
            QuestionnaireQuestion::factory()->create([
                'questionnaire_template_id' => $questionnaire->id,
                'position'                  => $i,
                'question_key'              => "q{$i}",
                'label'                     => "Question {$i}",
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(4, $response->json('data.meta.total'));
    }

    public function test_applicant_documents_now_returns_paginated_response(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job      = $this->makePublishedJob($store, $workflow);
        $app      = $this->makeApplication($job);
        $template = DocumentTemplate::factory()->create(['store_id' => $store->id]);

        ApplicantDocument::factory()->count(3)->create([
            'application_id'      => $app->id,
            'document_template_id'=> $template->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/applications/{$app->id}/documents?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(3, $response->json('data.meta.total'));
    }

    // =========================================================================
    // User index filters
    // =========================================================================

    public function test_user_index_can_filter_by_role(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $manager = User::factory()->create();
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/api/v1/users?role=store_manager');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals($manager->id, $response->json('data.data.0.id'));
    }

    public function test_user_index_can_search_by_name(): void
    {
        [$franchise, $store, $admin] = $this->makeAdminStore();

        $targetUser = User::factory()->create(['name' => 'SpecificName Jones']);
        UserStoreAccess::create(['user_id' => $targetUser->id, 'store_id' => $store->id, 'role' => 'viewer', 'access_scope' => 'store', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/api/v1/users?search=SpecificName');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals($targetUser->id, $response->json('data.data.0.id'));
    }
}
