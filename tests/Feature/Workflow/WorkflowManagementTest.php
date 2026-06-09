<?php

namespace Tests\Feature\Workflow;

use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowManagementTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAdminAndStore(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        return [$franchise, $store, $admin];
    }

    private function makeManagerAndStore(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id]);

        return [$franchise, $store, $manager];
    }

    // -----------------------------------------------------------------------
    // Role access
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_create_a_workflow(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Cashier Hiring',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cashier Hiring')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('hiring_workflows', [
            'store_id' => $store->id,
            'name' => 'Cashier Hiring',
        ]);
    }

    public function test_store_manager_can_create_a_workflow(): void
    {
        [, $store, $manager] = $this->makeManagerAndStore();

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Manager Workflow',
            ])
            ->assertCreated();
    }

    public function test_recruiter_cannot_create_a_workflow(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $recruiter = User::factory()->recruiter()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id]);

        $this->actingAs($recruiter)
            ->postJson("/api/v1/stores/{$store->id}/workflows", ['name' => 'Attempted'])
            ->assertForbidden();
    }

    public function test_viewer_cannot_create_a_workflow(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $viewer = User::factory()->create([
            'franchise_account_id' => $franchise->id,
            'role' => 'viewer',
            'access_scope' => 'store',
        ]);
        UserStoreAccess::create(['user_id' => $viewer->id, 'store_id' => $store->id]);

        $this->actingAs($viewer)
            ->postJson("/api/v1/stores/{$store->id}/workflows", ['name' => 'Attempted'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Create with stages
    // -----------------------------------------------------------------------

    public function test_can_create_workflow_with_stages(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Cashier Hiring',
                'stages' => [
                    ['name' => 'Applied', 'stage_type' => 'application', 'position' => 1, 'is_initial' => true],
                    ['name' => 'Screening', 'stage_type' => 'screening', 'position' => 2],
                    ['name' => 'Hired', 'stage_type' => 'hired', 'position' => 3, 'is_terminal' => true],
                ],
            ]);

        $response->assertCreated();

        $workflowId = $response->json('data.id');
        $this->assertDatabaseHas('hiring_workflows', ['id' => $workflowId, 'name' => 'Cashier Hiring']);
        $this->assertDatabaseHas('workflow_stages', ['hiring_workflow_id' => $workflowId, 'name' => 'Applied', 'is_initial' => true]);
        $this->assertDatabaseHas('workflow_stages', ['hiring_workflow_id' => $workflowId, 'name' => 'Screening']);
        $this->assertDatabaseHas('workflow_stages', ['hiring_workflow_id' => $workflowId, 'name' => 'Hired', 'is_terminal' => true]);

        $this->assertCount(3, $response->json('data.stages'));
    }

    public function test_workflow_creation_with_invalid_stages_is_atomic(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();

        // Duplicate positions trigger 422 BEFORE the transaction
        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Bad Workflow',
                'stages' => [
                    ['name' => 'Stage A', 'stage_type' => 'application', 'position' => 1, 'is_initial' => true],
                    ['name' => 'Stage B', 'stage_type' => 'screening', 'position' => 1], // duplicate position
                ],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('hiring_workflows', ['name' => 'Bad Workflow']);
        $this->assertDatabaseCount('workflow_stages', 0);
    }

    // -----------------------------------------------------------------------
    // Stage validation on creation
    // -----------------------------------------------------------------------

    public function test_cannot_create_two_initial_stages_in_request(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Bad Workflow',
                'stages' => [
                    ['name' => 'Stage A', 'stage_type' => 'application', 'position' => 1, 'is_initial' => true],
                    ['name' => 'Stage B', 'stage_type' => 'screening', 'position' => 2, 'is_initial' => true],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.stages.0', 'Only one initial stage is allowed per workflow.');
    }

    public function test_cannot_duplicate_stage_position_in_request(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Bad Workflow',
                'stages' => [
                    ['name' => 'Stage A', 'stage_type' => 'application', 'position' => 1, 'is_initial' => true],
                    ['name' => 'Stage B', 'stage_type' => 'screening', 'position' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.stages.0', 'Stage positions must be unique within a workflow.');
    }

    public function test_cannot_duplicate_stage_name_in_request(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows", [
                'name' => 'Bad Workflow',
                'stages' => [
                    ['name' => 'Applied', 'stage_type' => 'application', 'position' => 1, 'is_initial' => true],
                    ['name' => 'applied', 'stage_type' => 'screening', 'position' => 2],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.stages.0', 'Stage names must be unique within a workflow.');
    }

    // -----------------------------------------------------------------------
    // List
    // -----------------------------------------------------------------------

    public function test_can_list_workflows_for_store(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();
        HiringWorkflow::factory()->forStore($store)->count(3)->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows")
            ->assertOk();

        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_workflows_list_is_scoped_to_route_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        HiringWorkflow::factory()->forStore($storeA)->count(2)->create();
        HiringWorkflow::factory()->forStore($storeB)->count(3)->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/workflows")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    // -----------------------------------------------------------------------
    // Show
    // -----------------------------------------------------------------------

    public function test_can_show_workflow_with_stages(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $workflow->id)
            ->assertJsonStructure(['data' => ['stages']]);
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_can_update_workflow_name(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('hiring_workflows', ['id' => $workflow->id, 'name' => 'New Name']);
    }

    public function test_publishing_workflow_sets_published_at(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNotNull($workflow->fresh()->published_at);
    }

    public function test_cannot_change_status_of_archived_workflow(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();
        $workflow = HiringWorkflow::factory()->archived()->forStore($store)->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}", ['status' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Cannot change the status of an archived workflow.');
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_delete_workflow(): void
    {
        [, $store, $admin] = $this->makeAdminAndStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}")
            ->assertOk();

        $this->assertDatabaseMissing('hiring_workflows', ['id' => $workflow->id]);
    }

    public function test_store_manager_cannot_delete_workflow(): void
    {
        [, $store, $manager] = $this->makeManagerAndStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $this->actingAs($manager)
            ->deleteJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_workflow_from_different_store_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchise)->create();
        $storeB = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();

        // Request routes through storeA but the workflow belongs to storeB
        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/workflows/{$workflowB->id}")
            ->assertNotFound();
    }

    public function test_inaccessible_store_returns_403_on_workflow_routes(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess — manager cannot reach this store

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->id}/workflows")
            ->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/v1/stores/{$store->id}/workflows")
            ->assertUnauthorized();
    }
}
