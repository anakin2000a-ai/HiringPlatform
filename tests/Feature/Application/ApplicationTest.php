<?php

namespace Tests\Feature\Application;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Creates a store with a published job opening that has a workflow with an initial stage.
     */
    private function makePublishedJobOpening(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $initialStage = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();

        $job = JobOpening::factory()->withWorkflow($workflow)->published()->create();

        return [$franchise, $store, $admin, $workflow, $initialStage, $job];
    }

    private function validApplicantPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Apply — public route
    // -----------------------------------------------------------------------

    public function test_applicant_can_apply_to_published_job_opening(): void
    {
        [, $store, , , $initialStage, $job] = $this->makePublishedJobOpening();

        $response = $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'source' => 'career_page',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_stage_id', $initialStage->id);

        $this->assertDatabaseHas('applicants', ['email' => 'jane@example.com']);
        $this->assertDatabaseHas('applications', [
            'job_opening_id' => $job->id,
            'current_stage_id' => $initialStage->id,
            'status' => 'active',
        ]);
    }

    public function test_application_applied_at_is_set(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        $response = $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply",
            $this->validApplicantPayload()
        );

        $response->assertCreated();

        $application = Application::first();
        $this->assertNotNull($application->applied_at);
    }

    public function test_cannot_apply_to_draft_job_opening(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $job = JobOpening::factory()->withWorkflow($workflow)->create(); // draft

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply",
            $this->validApplicantPayload()
        )->assertUnprocessable();
    }

    public function test_cannot_apply_to_closed_job_opening(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $job = JobOpening::factory()->withWorkflow($workflow)->closed()->create();

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply",
            $this->validApplicantPayload()
        )->assertUnprocessable();
    }

    public function test_job_opening_must_belong_to_route_store_on_apply(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();

        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();
        $jobInB = JobOpening::factory()->withWorkflow($workflowB)->published()->create();

        $this->postJson("/api/v1/stores/{$storeA->id}/job-openings/{$jobInB->id}/apply",
            $this->validApplicantPayload()
        )->assertNotFound();
    }

    public function test_application_starts_at_workflow_initial_stage(): void
    {
        [, $store, , , $initialStage, $job] = $this->makePublishedJobOpening();

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply",
            $this->validApplicantPayload()
        )->assertCreated()
            ->assertJsonPath('data.current_stage_id', $initialStage->id);
    }

    // -----------------------------------------------------------------------
    // Applicant reuse
    // -----------------------------------------------------------------------

    public function test_applicant_is_reused_by_email(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        $existing = Applicant::factory()->create(['email' => 'reuse@example.com']);

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'first_name' => 'Different',
            'last_name' => 'Name',
            'email' => 'reuse@example.com',
        ])->assertCreated();

        $this->assertDatabaseCount('applicants', 1);

        $application = Application::first();
        $this->assertEquals($existing->id, $application->applicant_id);
    }

    public function test_applicant_is_reused_by_phone(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        $existing = Applicant::factory()->noEmail()->withPhone('+15555550100')->create();

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'first_name' => 'Different',
            'last_name' => 'Name',
            'phone' => '+15555550100',
        ])->assertCreated();

        $this->assertDatabaseCount('applicants', 1);

        $application = Application::first();
        $this->assertEquals($existing->id, $application->applicant_id);
    }

    public function test_duplicate_application_is_rejected(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        $applicant = Applicant::factory()->create(['email' => 'dup@example.com']);
        Application::factory()->create([
            'applicant_id' => $applicant->id,
            'job_opening_id' => $job->id,
        ]);

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'first_name' => 'Dup',
            'last_name' => 'Applicant',
            'email' => 'dup@example.com',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.job_opening_id.0', 'You have already applied to this job opening.');
    }

    public function test_cannot_apply_when_workflow_has_no_initial_stage(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        // No stages created — no initial stage
        $job = JobOpening::factory()->withWorkflow($workflow)->published()->create();

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply",
            $this->validApplicantPayload()
        )->assertUnprocessable()
            ->assertJsonPath('errors.job_opening_id.0', 'The workflow for this job opening has no initial stage.');
    }

    // -----------------------------------------------------------------------
    // Validation — apply request
    // -----------------------------------------------------------------------

    public function test_apply_requires_at_least_email_or_phone(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'first_name' => 'No',
            'last_name' => 'Contact',
            // no email, no phone
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_apply_requires_first_name(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'last_name' => 'Doe',
            'email' => 'test@example.com',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['first_name']]);
    }

    // -----------------------------------------------------------------------
    // Management routes — list
    // -----------------------------------------------------------------------

    public function test_can_list_applications_for_store(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);
        Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_applications_list_is_scoped_to_route_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowA = HiringWorkflow::factory()->forStore($storeA)->create();
        $stageA = WorkflowStage::factory()->forWorkflow($workflowA)->initial()->create();
        $jobA = JobOpening::factory()->withWorkflow($workflowA)->published()->create();

        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();
        $jobB = JobOpening::factory()->withWorkflow($workflowB)->published()->create();

        Application::factory()->atStage($stageA)->create(['job_opening_id' => $jobA->id]);
        Application::factory()->atStage($stageA)->create(['job_opening_id' => $jobA->id]);
        Application::factory()->atStage($stageB)->create(['job_opening_id' => $jobB->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/applications")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_can_filter_applications_by_status(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id, 'status' => 'active']);
        Application::factory()->atStage($initialStage)->rejected()->create(['job_opening_id' => $job->id]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications?status=active")
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('active', $response->json('data.data.0.status'));
    }

    // -----------------------------------------------------------------------
    // Management routes — show
    // -----------------------------------------------------------------------

    public function test_can_show_application(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        $application = Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonStructure(['data' => ['applicant', 'current_stage']]);
    }

    public function test_application_from_different_store_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();
        $jobB = JobOpening::factory()->withWorkflow($workflowB)->published()->create();
        $appInB = Application::factory()->atStage($stageB)->create(['job_opening_id' => $jobB->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/applications/{$appInB->id}")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Management routes — update
    // -----------------------------------------------------------------------

    public function test_can_update_application_status(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        $application = Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/applications/{$application->id}", [
                'status' => 'rejected',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertNotNull($application->fresh()->rejected_at);
    }

    public function test_rejecting_application_sets_rejected_at(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        $application = Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/applications/{$application->id}", ['status' => 'rejected'])
            ->assertOk();

        $this->assertNotNull($application->fresh()->rejected_at);
    }

    public function test_hiring_application_sets_hired_at(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        $application = Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/applications/{$application->id}", ['status' => 'hired'])
            ->assertOk();

        $this->assertNotNull($application->fresh()->hired_at);
    }

    public function test_invalid_status_is_rejected(): void
    {
        [, $store, $admin, , $initialStage, $job] = $this->makePublishedJobOpening();

        $application = Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/applications/{$application->id}", [
                'status' => 'invalid_status',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_recruiter_cannot_update_application(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $recruiter = User::factory()->create();
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id, 'role' => 'recruiter', 'access_scope' => 'store', 'status' => 'active']);

        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $stage = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $job = JobOpening::factory()->withWorkflow($workflow)->published()->create();
        $application = Application::factory()->atStage($stage)->create(['job_opening_id' => $job->id]);

        $this->actingAs($recruiter)
            ->patchJson("/api/v1/stores/{$store->id}/applications/{$application->id}", ['status' => 'rejected'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Store-scope enforcement
    // -----------------------------------------------------------------------

    public function test_all_management_routes_enforce_store_access(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->create();
        // No UserStoreAccess

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->id}/applications")
            ->assertForbidden();
    }

    public function test_unauthenticated_management_returns_401(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/v1/stores/{$store->id}/applications")
            ->assertUnauthorized();
    }

    public function test_apply_route_is_public_no_auth_needed(): void
    {
        [, $store, , , , $job] = $this->makePublishedJobOpening();

        // No actingAs — completely unauthenticated
        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply",
            $this->validApplicantPayload()
        )->assertCreated();
    }

    // -----------------------------------------------------------------------
    // No policies
    // -----------------------------------------------------------------------

    public function test_no_policy_classes_exist_for_applications(): void
    {
        $this->assertFileDoesNotExist(app_path('Policies/ApplicationPolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/ApplicantPolicy.php'));
    }
}
