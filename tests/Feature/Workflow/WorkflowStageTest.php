<?php

namespace Tests\Feature\Workflow;

use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowStageTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeContext(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        return [$franchise, $store, $admin, $workflow];
    }

    // -----------------------------------------------------------------------
    // Create stage
    // -----------------------------------------------------------------------

    public function test_can_add_stage_to_workflow(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages", [
                'name' => 'Screening',
                'stage_type' => 'screening',
                'position' => 1,
                'is_initial' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Screening')
            ->assertJsonPath('data.is_initial', true);

        $this->assertDatabaseHas('workflow_stages', [
            'hiring_workflow_id' => $workflow->id,
            'name' => 'Screening',
        ]);
    }

    public function test_cannot_add_second_initial_stage(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        // First initial stage
        WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages", [
                'name' => 'Another Initial',
                'stage_type' => 'screening',
                'position' => 2,
                'is_initial' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_initial.0', 'This workflow already has an initial stage.');
    }

    public function test_cannot_duplicate_stage_position(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1, 'is_initial' => false]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages", [
                'name' => 'Different Stage',
                'stage_type' => 'screening',
                'position' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.position.0', 'Position 1 is already used in this workflow.');
    }

    public function test_cannot_duplicate_stage_name_within_workflow(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Applied', 'position' => 1]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages", [
                'name' => 'APPLIED', // case-insensitive duplicate
                'stage_type' => 'application',
                'position' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', "A stage named 'APPLIED' already exists in this workflow.");
    }

    // -----------------------------------------------------------------------
    // List stages
    // -----------------------------------------------------------------------

    public function test_can_list_stages_for_workflow(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);
        WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // -----------------------------------------------------------------------
    // Update stage
    // -----------------------------------------------------------------------

    public function test_can_update_stage_name(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();
        $stage = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Old Name', 'position' => 1]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages/{$stage->id}", [
                'name' => 'New Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_cannot_update_stage_to_duplicate_position(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Stage A', 'position' => 1]);
        $stageB = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Stage B', 'position' => 2]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages/{$stageB->id}", [
                'position' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.position.0', 'Position 1 is already used in this workflow.');
    }

    // -----------------------------------------------------------------------
    // Delete stage
    // -----------------------------------------------------------------------

    public function test_can_delete_stage(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();
        $stage = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages/{$stage->id}")
            ->assertOk();

        $this->assertDatabaseMissing('workflow_stages', ['id' => $stage->id]);
    }

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_stage_from_different_workflow_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $workflowA = HiringWorkflow::factory()->forStore($store)->create();
        $workflowB = HiringWorkflow::factory()->forStore($store)->create();
        $stageB = WorkflowStage::factory()->forWorkflow($workflowB)->create(['position' => 1]);

        // Route through workflowA but stage belongs to workflowB
        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/workflows/{$workflowA->id}/stages/{$stageB->id}", [
                'name' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_all_stage_routes_enforce_store_access(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess — store is inaccessible

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages")
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages", [
                'name' => 'Stage', 'stage_type' => 'application', 'position' => 1,
            ])
            ->assertForbidden();
    }
}
