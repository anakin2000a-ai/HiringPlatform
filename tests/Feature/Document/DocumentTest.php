<?php

namespace Tests\Feature\Document;

use App\Models\Application;
use App\Models\ApplicantDocument;
use App\Models\DocumentTemplate;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\StageDocumentRequirement;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeStore(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();
        $admin     = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        return [$franchise, $store, $admin];
    }

    private function makeTemplate(Store $store, array $overrides = []): DocumentTemplate
    {
        return DocumentTemplate::factory()->forStore($store)->create($overrides);
    }

    private function makeApplication(Store $store): array
    {
        $workflow    = HiringWorkflow::factory()->forStore($store)->create();
        $stage       = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $job         = JobOpening::factory()->withWorkflow($workflow)->published()->create();
        $application = Application::factory()->atStage($stage)->create(['job_opening_id' => $job->id]);

        return [$workflow, $stage, $job, $application];
    }

    private function makeRequirement(WorkflowStage $stage, DocumentTemplate $template, array $overrides = []): StageDocumentRequirement
    {
        return StageDocumentRequirement::factory()->create(array_merge([
            'workflow_stage_id'    => $stage->id,
            'document_template_id' => $template->id,
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Document template — CRUD
    // -----------------------------------------------------------------------

    public function test_can_list_document_templates(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->makeTemplate($store);
        $this->makeTemplate($store);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/document-templates")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_list_scoped_to_store(): void
    {
        [$franchise, $storeA, $admin] = $this->makeStore();
        $storeB = Store::factory()->for($franchise)->create();

        $this->makeTemplate($storeA);
        $this->makeTemplate($storeB);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/document-templates")
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_can_create_document_template(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/document-templates", [
                'name'               => 'Offer Letter',
                'document_type'      => 'offer_letter',
                'requires_signature' => true,
                'description'        => 'Standard offer letter',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Offer Letter')
            ->assertJsonPath('data.document_type', 'offer_letter')
            ->assertJsonPath('data.requires_signature', true)
            ->assertJsonPath('data.store_id', $store->id);

        $this->assertDatabaseHas('document_templates', [
            'store_id'      => $store->id,
            'name'          => 'Offer Letter',
            'created_by'    => $admin->id,
        ]);
    }

    public function test_create_requires_name_and_document_type(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/document-templates", [])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['name', 'document_type']]);
    }

    public function test_can_show_document_template(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/document-templates/{$template->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $template->id);
    }

    public function test_show_returns_404_for_wrong_store(): void
    {
        [$franchise, $storeA, $admin] = $this->makeStore();
        $storeB   = Store::factory()->for($franchise)->create();
        $template = $this->makeTemplate($storeB);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/document-templates/{$template->id}")
            ->assertNotFound();
    }

    public function test_can_update_document_template(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/document-templates/{$template->id}", [
                'name' => 'Updated Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_can_delete_document_template(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/document-templates/{$template->id}")
            ->assertOk();

        $this->assertDatabaseMissing('document_templates', ['id' => $template->id]);
    }

    // -----------------------------------------------------------------------
    // Document template — authorization
    // -----------------------------------------------------------------------

    public function test_cannot_access_templates_for_inaccessible_store(): void
    {
        [$franchise, $store] = $this->makeStore();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->id}/document-templates")
            ->assertForbidden();
    }

    public function test_recruiter_cannot_create_document_template(): void
    {
        [$franchise, $store] = $this->makeStore();
        $recruiter = User::factory()->recruiter()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id]);

        $this->actingAs($recruiter)
            ->postJson("/api/v1/stores/{$store->id}/document-templates", [
                'name'          => 'Doc',
                'document_type' => 'contract',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_document_template_routes(): void
    {
        [, $store] = $this->makeStore();

        $this->getJson("/api/v1/stores/{$store->id}/document-templates")
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // Stage document requirements — CRUD
    // -----------------------------------------------------------------------

    public function test_can_create_stage_document_requirement(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [$workflow, $stage] = $this->makeApplication($store);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/workflow-stages/{$stage->id}/document-requirements", [
                'document_template_id'       => $template->id,
                'is_required'                => true,
                'due_days_after_stage_entry' => 3,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.workflow_stage_id', $stage->id)
            ->assertJsonPath('data.document_template_id', $template->id)
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.due_days_after_stage_entry', 3);

        $this->assertDatabaseHas('stage_document_requirements', [
            'workflow_stage_id'    => $stage->id,
            'document_template_id' => $template->id,
        ]);
    }

    public function test_cannot_assign_template_from_different_store_to_stage(): void
    {
        [$franchise, $storeA, $admin] = $this->makeStore();
        $storeB   = Store::factory()->for($franchise)->create();
        $template = $this->makeTemplate($storeB);

        [, $stage] = $this->makeApplication($storeA);

        $this->actingAs($admin)
            ->postJson("/api/v1/workflow-stages/{$stage->id}/document-requirements", [
                'document_template_id' => $template->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.document_template_id.0', 'The document template does not belong to this store.');
    }

    public function test_duplicate_stage_document_requirement_is_rejected(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage] = $this->makeApplication($store);

        $this->makeRequirement($stage, $template);

        $this->actingAs($admin)
            ->postJson("/api/v1/workflow-stages/{$stage->id}/document-requirements", [
                'document_template_id' => $template->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.document_template_id.0', 'This document template is already required for this stage.');
    }

    public function test_can_list_document_requirements_for_stage(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template1 = $this->makeTemplate($store);
        $template2 = $this->makeTemplate($store);
        [, $stage] = $this->makeApplication($store);

        $this->makeRequirement($stage, $template1);
        $this->makeRequirement($stage, $template2);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workflow-stages/{$stage->id}/document-requirements")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_show_stage_document_requirement(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $this->actingAs($admin)
            ->getJson("/api/v1/stage-document-requirements/{$requirement->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $requirement->id);
    }

    public function test_can_update_stage_document_requirement(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stage-document-requirements/{$requirement->id}", [
                'is_required'                => false,
                'due_days_after_stage_entry' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_required', false)
            ->assertJsonPath('data.due_days_after_stage_entry', 7);
    }

    public function test_can_delete_stage_document_requirement(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stage-document-requirements/{$requirement->id}")
            ->assertOk();

        $this->assertDatabaseMissing('stage_document_requirements', ['id' => $requirement->id]);
    }

    public function test_stage_document_requirement_enforces_store_access(): void
    {
        [$franchise, $store] = $this->makeStore();
        $template  = $this->makeTemplate($store);
        [, $stage] = $this->makeApplication($store);

        $outsider = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);
        $otherFranchise = FranchiseAccount::factory()->create();
        $outsider2 = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $otherFranchise->id]);

        $this->actingAs($outsider2)
            ->postJson("/api/v1/workflow-stages/{$stage->id}/document-requirements", [
                'document_template_id' => $template->id,
            ])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Applicant documents — lifecycle
    // -----------------------------------------------------------------------

    public function test_can_initiate_applicant_document(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/applications/{$application->id}/documents", [
                'stage_document_requirement_id' => $requirement->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.document_template_id', $template->id)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('applicant_documents', [
            'application_id'              => $application->id,
            'stage_document_requirement_id' => $requirement->id,
            'status'                      => 'pending',
        ]);
    }

    public function test_can_list_applicant_documents(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/applications/{$application->id}/documents")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_submit_applicant_document(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('applicant_documents', [
            'id'     => $document->id,
            'status' => 'submitted',
        ]);
    }

    public function test_document_requiring_signature_can_be_signed_then_approved(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store, ['requires_signature' => true]);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
            'submitted_at'                 => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/sign", [
                'external_signature_id' => 'sig-abc-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'signed');

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('applicant_documents', [
            'id'          => $document->id,
            'status'      => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_document_not_requiring_signature_can_be_approved_after_submission(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store, ['requires_signature' => false]);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
            'submitted_at'                 => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_rejected_document_can_be_resubmitted(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'rejected',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_reject_requires_rejected_reason(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['rejected_reason']]);
    }

    public function test_can_reject_document_with_reason(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/reject", [
                'rejected_reason' => 'Document is blurry',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejected_reason', 'Document is blurry');
    }

    public function test_approved_document_cannot_be_resubmitted(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'approved',
            'approved_at'                  => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/submit")
            ->assertUnprocessable();
    }

    public function test_approved_document_cannot_be_rejected(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'approved',
            'approved_at'                  => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/reject", [
                'rejected_reason' => 'Trying to reject',
            ])
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------------
    // Activity records and outbox events
    // -----------------------------------------------------------------------

    public function test_submit_creates_workflow_activity(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/submit")
            ->assertOk();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $application->id,
            'event_type'     => 'applicant_document_submitted',
        ]);
    }

    public function test_submit_creates_outbox_event(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/submit")
            ->assertOk();

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.document.submitted',
            'status'     => 'pending',
        ]);
    }

    public function test_sign_creates_workflow_activity_and_outbox_event(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store, ['requires_signature' => true]);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
            'submitted_at'                 => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/sign")
            ->assertOk();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $application->id,
            'event_type'     => 'applicant_document_signed',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.document.signed',
            'status'     => 'pending',
        ]);
    }

    public function test_approve_creates_workflow_activity_and_outbox_event(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store, ['requires_signature' => false]);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
            'submitted_at'                 => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $application->id,
            'event_type'     => 'applicant_document_approved',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.document.approved',
            'status'     => 'pending',
        ]);
    }

    public function test_reject_creates_workflow_activity_and_outbox_event(): void
    {
        [, $store, $admin] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $document = ApplicantDocument::factory()->create([
            'application_id'               => $application->id,
            'workflow_stage_id'            => $stage->id,
            'stage_document_requirement_id' => $requirement->id,
            'document_template_id'         => $template->id,
            'status'                       => 'submitted',
            'submitted_at'                 => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/applicant-documents/{$document->id}/reject", [
                'rejected_reason' => 'Missing page',
            ])
            ->assertOk();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $application->id,
            'event_type'     => 'applicant_document_rejected',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.document.rejected',
            'status'     => 'pending',
        ]);
    }

    // -----------------------------------------------------------------------
    // Store access enforcement on applicant document routes
    // -----------------------------------------------------------------------

    public function test_unauthorized_store_access_is_forbidden_on_applicant_document_routes(): void
    {
        [$franchise, $store] = $this->makeStore();
        $template = $this->makeTemplate($store);
        [, $stage, , $application] = $this->makeApplication($store);
        $requirement = $this->makeRequirement($stage, $template);

        $otherFranchise = FranchiseAccount::factory()->create();
        $outsider = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $otherFranchise->id]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/applications/{$application->id}/documents")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson("/api/v1/applications/{$application->id}/documents", [
                'stage_document_requirement_id' => $requirement->id,
            ])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // No policies
    // -----------------------------------------------------------------------

    public function test_no_policy_classes_exist_for_documents(): void
    {
        $this->assertFileDoesNotExist(app_path('Policies/DocumentTemplatePolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/StageDocumentRequirementPolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/ApplicantDocumentPolicy.php'));
    }
}
