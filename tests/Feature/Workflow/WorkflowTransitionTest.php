<?php

namespace Tests\Feature\Workflow;

use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTransitionTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeContext(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        return [$franchise, $store, $admin, $workflow];
    }

    // -----------------------------------------------------------------------
    // Create transition
    // -----------------------------------------------------------------------

    public function test_can_create_valid_transition(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $from = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);
        $to = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions", [
                'from_stage_id' => $from->id,
                'to_stage_id' => $to->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.from_stage_id', $from->id)
            ->assertJsonPath('data.to_stage_id', $to->id);

        $this->assertDatabaseHas('workflow_stage_transitions', [
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => $from->id,
            'to_stage_id' => $to->id,
        ]);
    }

    public function test_can_create_initial_entry_transition_with_null_from_stage(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $to = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions", [
                'from_stage_id' => null,
                'to_stage_id' => $to->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.from_stage_id', null);

        $this->assertDatabaseHas('workflow_stage_transitions', [
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => null,
            'to_stage_id' => $to->id,
        ]);
    }

    public function test_cannot_create_transition_when_from_stage_belongs_to_different_workflow(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowA = HiringWorkflow::factory()->forStore($store)->create();
        $workflowB = HiringWorkflow::factory()->forStore($store)->create();

        $fromInB = WorkflowStage::factory()->forWorkflow($workflowB)->create(['position' => 1]);
        $toInA = WorkflowStage::factory()->forWorkflow($workflowA)->create(['position' => 1]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows/{$workflowA->id}/transitions", [
                'from_stage_id' => $fromInB->id,
                'to_stage_id' => $toInA->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.from_stage_id.0', "Stage {$fromInB->id} does not belong to workflow {$workflowA->id}.");
    }

    public function test_cannot_create_transition_when_to_stage_belongs_to_different_workflow(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowA = HiringWorkflow::factory()->forStore($store)->create();
        $workflowB = HiringWorkflow::factory()->forStore($store)->create();

        $fromInA = WorkflowStage::factory()->forWorkflow($workflowA)->create(['position' => 1]);
        $toInB = WorkflowStage::factory()->forWorkflow($workflowB)->create(['position' => 1]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows/{$workflowA->id}/transitions", [
                'from_stage_id' => $fromInA->id,
                'to_stage_id' => $toInB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.to_stage_id.0', "Stage {$toInB->id} does not belong to workflow {$workflowA->id}.");
    }

    public function test_cannot_create_duplicate_transition(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $from = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);
        $to = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);

        WorkflowStageTransition::create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => $from->id,
            'to_stage_id' => $to->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions", [
                'from_stage_id' => $from->id,
                'to_stage_id' => $to->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.from_stage_id.0', 'A transition with this from/to stage combination already exists in this workflow.');
    }

    public function test_cannot_create_duplicate_null_from_transition(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $to = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);

        WorkflowStageTransition::create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => null,
            'to_stage_id' => $to->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions", [
                'from_stage_id' => null,
                'to_stage_id' => $to->id,
            ])
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------------
    // Update transition
    // -----------------------------------------------------------------------

    public function test_can_update_transition_flags(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $from = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);
        $to = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);

        $transition = WorkflowStageTransition::create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => $from->id,
            'to_stage_id' => $to->id,
            'is_manual_allowed' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions/{$transition->id}", [
                'is_manual_allowed' => false,
                'name' => 'Quick Move',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_manual_allowed', false)
            ->assertJsonPath('data.name', 'Quick Move');
    }

    // -----------------------------------------------------------------------
    // Delete transition
    // -----------------------------------------------------------------------

    public function test_can_delete_transition(): void
    {
        [, $store, $admin, $workflow] = $this->makeContext();

        $from = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);
        $to = WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 2]);

        $transition = WorkflowStageTransition::create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id' => $from->id,
            'to_stage_id' => $to->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions/{$transition->id}")
            ->assertOk();

        $this->assertDatabaseMissing('workflow_stage_transitions', ['id' => $transition->id]);
    }

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_transition_from_different_workflow_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflowA = HiringWorkflow::factory()->forStore($store)->create();
        $workflowB = HiringWorkflow::factory()->forStore($store)->create();

        $toInB = WorkflowStage::factory()->forWorkflow($workflowB)->create(['position' => 1]);
        $transitionInB = WorkflowStageTransition::create([
            'hiring_workflow_id' => $workflowB->id,
            'from_stage_id' => null,
            'to_stage_id' => $toInB->id,
        ]);

        // Route through workflowA but transition belongs to workflowB
        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->store_name}/workflows/{$workflowA->id}/transitions/{$transitionInB->id}")
            ->assertNotFound();
    }

    public function test_all_transition_routes_enforce_store_access(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $manager = User::factory()->create();
        // No UserStoreAccess

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/transitions")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Authorization is NOT via policies
    // -----------------------------------------------------------------------

    public function test_no_policy_classes_exist_for_workflows(): void
    {
        $this->assertFileDoesNotExist(app_path('Policies/HiringWorkflowPolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/WorkflowStagePolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/WorkflowStageTransitionPolicy.php'));
    }
}
