<?php

namespace Tests\Feature\JobOpening;

use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOpeningTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAdminContext(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        return [$franchise, $store, $admin, $workflow];
    }

    private function makeManagerContext(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->create();
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        return [$franchise, $store, $manager, $workflow];
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_create_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings", [
                'hiring_workflow_id' => $workflow->id,
                'title' => 'Cashier',
                'openings_count' => 3,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Cashier')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.openings_count', 3);

        $this->assertDatabaseHas('job_openings', [
            'store_id' => $store->id,
            'title' => 'Cashier',
            'status' => 'draft',
        ]);
    }

    public function test_store_manager_can_create_job_opening(): void
    {
        [, $store, $manager, $workflow] = $this->makeManagerContext();

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings", [
                'hiring_workflow_id' => $workflow->id,
                'title' => 'Barista',
            ])
            ->assertCreated();
    }

    public function test_recruiter_cannot_create_job_opening(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $recruiter = User::factory()->create();
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id, 'role' => 'recruiter', 'access_scope' => 'store', 'status' => 'active']);
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $this->actingAs($recruiter)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings", [
                'hiring_workflow_id' => $workflow->id,
                'title' => 'Cashier',
            ])
            ->assertForbidden();
    }

    public function test_cannot_create_job_opening_for_inaccessible_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $manager = User::factory()->create();
        // No UserStoreAccess created

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings", [
                'hiring_workflow_id' => $workflow->id,
                'title' => 'Cashier',
            ])
            ->assertForbidden();
    }

    public function test_cannot_use_workflow_from_another_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$storeA->store_name}/job-openings", [
                'hiring_workflow_id' => $workflowB->id,
                'title' => 'Cashier',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.hiring_workflow_id.0', 'The selected workflow does not belong to this store.');
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function test_title_is_required(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings", [
                'hiring_workflow_id' => $workflow->id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['title']]);
    }

    public function test_openings_count_must_be_at_least_one(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings", [
                'hiring_workflow_id' => $workflow->id,
                'title' => 'Cashier',
                'openings_count' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['openings_count']]);
    }

    // -----------------------------------------------------------------------
    // List
    // -----------------------------------------------------------------------

    public function test_can_list_job_openings_for_store(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        JobOpening::factory()->withWorkflow($workflow)->count(3)->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->store_name}/job-openings")
            ->assertOk();

        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_job_openings_list_is_scoped_to_route_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowA = HiringWorkflow::factory()->forStore($storeA)->create();
        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        JobOpening::factory()->withWorkflow($workflowA)->count(2)->create();
        JobOpening::factory()->withWorkflow($workflowB)->count(4)->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->store_name}/job-openings")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_can_filter_job_openings_by_status(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        JobOpening::factory()->withWorkflow($workflow)->create(['status' => 'draft']);
        JobOpening::factory()->withWorkflow($workflow)->published()->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->store_name}/job-openings?status=draft")
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('draft', $response->json('data.data.0.status'));
    }

    // -----------------------------------------------------------------------
    // Show
    // -----------------------------------------------------------------------

    public function test_can_show_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create(['title' => 'Barista']);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $job->id)
            ->assertJsonPath('data.title', 'Barista');
    }

    public function test_job_opening_from_different_store_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $jobInB = JobOpening::factory()->withWorkflow($workflowB)->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->store_name}/job-openings/{$jobInB->id}")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_can_update_job_opening_title(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create(['title' => 'Old Title']);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}", [
                'title' => 'New Title',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title');

        $this->assertDatabaseHas('job_openings', ['id' => $job->id, 'title' => 'New Title']);
    }

    public function test_cannot_update_status_via_patch(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create();

        // status is not in UpdateJobOpeningRequest rules — it should be silently ignored
        // The status should remain draft after the request
        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}", [
                'title' => 'Updated',
                'status' => 'published',
            ])
            ->assertOk();

        $this->assertEquals('draft', $job->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Publish
    // -----------------------------------------------------------------------

    public function test_can_publish_a_draft_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotNull($job->fresh()->published_at);
        $this->assertEquals('published', $job->fresh()->status);
    }

    public function test_cannot_publish_an_already_published_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->published()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Only draft job openings can be published.');
    }

    // -----------------------------------------------------------------------
    // Close
    // -----------------------------------------------------------------------

    public function test_can_close_a_published_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->published()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($job->fresh()->closed_at);
        $this->assertEquals('closed', $job->fresh()->status);
    }

    public function test_can_close_a_draft_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_cannot_close_an_already_closed_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->closed()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/close")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This job opening is already closed.');
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_delete_job_opening(): void
    {
        [, $store, $admin, $workflow] = $this->makeAdminContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}")
            ->assertOk();

        $this->assertDatabaseMissing('job_openings', ['id' => $job->id]);
    }

    public function test_store_manager_cannot_delete_job_opening(): void
    {
        [, $store, $manager, $workflow] = $this->makeManagerContext();
        $job = JobOpening::factory()->withWorkflow($workflow)->create();

        $this->actingAs($manager)
            ->deleteJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Store-scope enforcement
    // -----------------------------------------------------------------------

    public function test_all_job_opening_routes_enforce_store_access_middleware(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $job = JobOpening::factory()->withWorkflow($workflow)->create();
        $manager = User::factory()->create();
        // No UserStoreAccess

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->store_name}/job-openings")
            ->assertForbidden();

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}")
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/publish")
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->store_name}/job-openings/{$job->id}/close")
            ->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/v1/stores/{$store->store_name}/job-openings")
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // No policies
    // -----------------------------------------------------------------------

    public function test_no_policy_classes_exist_for_job_openings(): void
    {
        $this->assertFileDoesNotExist(app_path('Policies/JobOpeningPolicy.php'));
    }
}
