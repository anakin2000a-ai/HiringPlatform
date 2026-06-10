<?php

namespace Tests\Feature\Application;

use App\Models\Application;
use App\Models\ApplicationStageTransition;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\OutboxEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowActivity;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MoveApplicationStageTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Creates a full workflow setup:
     *   Applied (initial) -> Screening -> Hired (terminal) / Rejected (terminal)
     *
     * Returns [$franchise, $store, $admin, $workflow, $applied, $screening, $hired, $rejected, $job, $application]
     */
    private function makeSetup(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        $applied   = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create(['name' => 'Applied', 'stage_type' => 'application']);
        $screening = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Screening', 'stage_type' => 'screening', 'position' => 2]);
        $hired     = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Hired', 'stage_type' => 'hired', 'is_terminal' => true, 'position' => 3]);
        $rejected  = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Rejected', 'stage_type' => 'rejected', 'is_terminal' => true, 'position' => 4]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $applied->id,
            'to_stage_id'        => $screening->id,
            'is_manual_allowed'  => true,
        ]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $screening->id,
            'to_stage_id'        => $hired->id,
            'is_manual_allowed'  => true,
        ]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $applied->id,
            'to_stage_id'        => $rejected->id,
            'is_manual_allowed'  => true,
        ]);

        $job = JobOpening::factory()->withWorkflow($workflow)->published()->create();
        $application = Application::factory()->atStage($applied)->create(['job_opening_id' => $job->id]);

        return [$franchise, $store, $admin, $workflow, $applied, $screening, $hired, $rejected, $job, $application];
    }

    private function moveUrl(Store $store, Application $application): string
    {
        return "/api/v1/stores/{$store->id}/applications/{$application->id}/move-stage";
    }

    private function activitiesUrl(Store $store, Application $application): string
    {
        return "/api/v1/stores/{$store->id}/applications/{$application->id}/activities";
    }

    // -----------------------------------------------------------------------
    // Successful movement
    // -----------------------------------------------------------------------

    public function test_can_move_application_to_valid_next_stage(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk()
            ->assertJsonPath('data.current_stage_id', $screening->id);
    }

    public function test_move_updates_current_stage_id_in_database(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $this->assertEquals($screening->id, $application->fresh()->current_stage_id);
    }

    public function test_move_creates_stage_transition_record(): void
    {
        [, $store, $admin, , $applied, $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), [
                'to_stage_id' => $screening->id,
                'reason'      => 'Passed initial screening',
            ])
            ->assertOk();

        $this->assertDatabaseHas('application_stage_transitions', [
            'application_id'  => $application->id,
            'from_stage_id'   => $applied->id,
            'to_stage_id'     => $screening->id,
            'changed_by'      => $admin->id,
            'transition_type' => 'manual',
            'reason'          => 'Passed initial screening',
        ]);
    }

    public function test_move_creates_workflow_activity(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $application->id,
            'store_id'       => $store->id,
            'actor_type'     => 'user',
            'actor_id'       => $admin->id,
            'event_type'     => 'stage_moved',
        ]);
    }

    public function test_move_creates_outbox_event(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.application.stage_changed',
            'status'     => 'pending',
        ]);
    }

    public function test_reason_is_optional(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $transition = ApplicationStageTransition::where('application_id', $application->id)->first();
        $this->assertNull($transition->reason);
    }

    public function test_transition_type_defaults_to_manual(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $transition = ApplicationStageTransition::where('application_id', $application->id)->first();
        $this->assertEquals('manual', $transition->transition_type);
    }

    public function test_transition_created_at_is_set(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $transition = ApplicationStageTransition::where('application_id', $application->id)->first();
        $this->assertNotNull($transition->created_at);
    }

    // -----------------------------------------------------------------------
    // Terminal stage status transitions
    // -----------------------------------------------------------------------

    public function test_moving_to_hired_terminal_stage_sets_status_and_hired_at(): void
    {
        Queue::fake();
        [, $store, $admin, , , $screening, $hired, , , $application] = $this->makeSetup();

        $application->update(['current_stage_id' => $screening->id]);

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $hired->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'hired');

        $fresh = $application->fresh();
        $this->assertEquals('hired', $fresh->status);
        $this->assertNotNull($fresh->hired_at);
    }

    public function test_moving_to_rejected_terminal_stage_sets_status_and_rejected_at(): void
    {
        Queue::fake();
        [, $store, $admin, , , , , $rejected, , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $rejected->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $fresh = $application->fresh();
        $this->assertEquals('rejected', $fresh->status);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_moving_to_non_terminal_stage_does_not_change_status(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    // -----------------------------------------------------------------------
    // Validation failures
    // -----------------------------------------------------------------------

    public function test_cannot_move_to_stage_not_in_workflow(): void
    {
        [, $store, $admin, , , , , , , $application] = $this->makeSetup();

        $otherWorkflow = HiringWorkflow::factory()->forStore($store)->create();
        $otherStage    = WorkflowStage::factory()->forWorkflow($otherWorkflow)->create();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $otherStage->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.to_stage_id.0', 'The target stage does not belong to this application\'s workflow.');
    }

    public function test_cannot_move_without_defined_transition(): void
    {
        [, $store, $admin, $workflow, , , , , , $application] = $this->makeSetup();

        $orphan = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Orphan', 'position' => 10]);
        // No WorkflowStageTransition created

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $orphan->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.to_stage_id.0', 'This stage transition is not allowed.');
    }

    public function test_cannot_move_when_transition_is_not_manual_allowed(): void
    {
        [, $store, $admin, $workflow, $applied, , , , , $application] = $this->makeSetup();

        $autoOnly = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'AutoOnly', 'position' => 5]);
        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'   => $workflow->id,
            'from_stage_id'        => $applied->id,
            'to_stage_id'          => $autoOnly->id,
            'is_manual_allowed'    => false,
            'is_automatic_allowed' => true,
        ]);

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $autoOnly->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.to_stage_id.0', 'This stage transition is not allowed.');
    }

    public function test_to_stage_id_is_required(): void
    {
        [, $store, $admin, , , , , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), [])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['to_stage_id']]);
    }

    public function test_to_stage_id_must_exist_in_database(): void
    {
        [, $store, $admin, , , , , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => 99999])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['to_stage_id']]);
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    public function test_store_manager_with_access_can_move_stage(): void
    {
        [, $store, , , , $screening, , , , $application] = $this->makeSetup();

        $franchise = FranchiseAccount::find($store->franchise_account_id);
        $manager   = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id]);

        $this->actingAs($manager)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();
    }

    public function test_recruiter_cannot_move_stage(): void
    {
        [, $store, , , , $screening, , , , $application] = $this->makeSetup();

        $franchise = FranchiseAccount::find($store->franchise_account_id);
        $recruiter = User::factory()->recruiter()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id]);

        $this->actingAs($recruiter)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_move_stage(): void
    {
        [, $store, , , , $screening, , , , $application] = $this->makeSetup();

        $this->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertUnauthorized();
    }

    public function test_store_access_is_enforced_on_move_stage(): void
    {
        [, $store, , , , $screening, , , , $application] = $this->makeSetup();

        $franchise = FranchiseAccount::find($store->franchise_account_id);
        $manager   = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess

        $this->actingAs($manager)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertForbidden();
    }

    public function test_application_from_different_store_returns_404_on_move(): void
    {
        [$franchise, $store, $admin] = $this->makeSetup();

        $storeB    = Store::factory()->for($franchise)->create();
        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB    = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();
        $jobB      = JobOpening::factory()->withWorkflow($workflowB)->published()->create();
        $appB      = Application::factory()->atStage($stageB)->create(['job_opening_id' => $jobB->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$appB->id}/move-stage", [
                'to_stage_id' => $stageB->id,
            ])
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Activity and outbox data integrity
    // -----------------------------------------------------------------------

    public function test_activity_old_and_new_value_contain_stage_ids(): void
    {
        [, $store, $admin, , $applied, $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $activity = WorkflowActivity::where('application_id', $application->id)->first();
        $this->assertEquals($applied->id, $activity->old_value['stage_id']);
        $this->assertEquals($screening->id, $activity->new_value['stage_id']);
    }

    public function test_activity_workflow_stage_id_is_set_to_destination_stage(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $activity = WorkflowActivity::where('application_id', $application->id)->first();
        $this->assertEquals($screening->id, $activity->workflow_stage_id);
    }

    public function test_outbox_event_contains_correct_payload_fields(): void
    {
        [, $store, $admin, , $applied, $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $event = OutboxEvent::first();
        $this->assertEquals('hiring.application.stage_changed', $event->event_type);
        $this->assertEquals('pending', $event->status);
        $this->assertEquals($application->id, $event->payload['application_id']);
        $this->assertEquals($applied->id, $event->payload['from_stage_id']);
        $this->assertEquals($screening->id, $event->payload['to_stage_id']);
    }

    // -----------------------------------------------------------------------
    // Activities endpoint
    // -----------------------------------------------------------------------

    public function test_can_list_activities_for_application(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        // Create an activity by moving stage
        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->getJson($this->activitiesUrl($store, $application))
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('stage_moved', $response->json('data.data.0.event_type'));
    }

    public function test_activities_are_scoped_to_application(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        // Move stage on our application to generate an activity
        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id]);

        // Create a second application with no activities
        $job2  = JobOpening::factory()->withWorkflow($application->jobOpening->hiringWorkflow)->published()->create();
        $application2 = Application::factory()
            ->atStage(WorkflowStage::find($application->fresh()->current_stage_id))
            ->create(['job_opening_id' => $job2->id]);

        $response = $this->actingAs($admin)
            ->getJson($this->activitiesUrl($store, $application2))
            ->assertOk();

        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_activities_endpoint_enforces_store_scope(): void
    {
        [, $store, , , , , , , , $application] = $this->makeSetup();

        $franchise = FranchiseAccount::find($store->franchise_account_id);
        $manager   = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess

        $this->actingAs($manager)
            ->getJson($this->activitiesUrl($store, $application))
            ->assertForbidden();
    }

    public function test_activities_endpoint_requires_auth(): void
    {
        [, $store, , , , , , , , $application] = $this->makeSetup();

        $this->getJson($this->activitiesUrl($store, $application))
            ->assertUnauthorized();
    }

    public function test_activities_endpoint_returns_404_for_wrong_store(): void
    {
        [$franchise, $store, $admin] = $this->makeSetup();

        $storeB    = Store::factory()->for($franchise)->create();
        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB    = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();
        $jobB      = JobOpening::factory()->withWorkflow($workflowB)->published()->create();
        $appB      = Application::factory()->atStage($stageB)->create(['job_opening_id' => $jobB->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/applications/{$appB->id}/activities")
            ->assertNotFound();
    }

    public function test_activities_response_includes_expected_fields(): void
    {
        [, $store, $admin, , , $screening, , , , $application] = $this->makeSetup();

        $this->actingAs($admin)
            ->postJson($this->moveUrl($store, $application), ['to_stage_id' => $screening->id]);

        $response = $this->actingAs($admin)
            ->getJson($this->activitiesUrl($store, $application))
            ->assertOk();

        $activity = $response->json('data.data.0');
        $this->assertArrayHasKey('event_type', $activity);
        $this->assertArrayHasKey('actor_type', $activity);
        $this->assertArrayHasKey('actor_id', $activity);
        $this->assertArrayHasKey('old_value', $activity);
        $this->assertArrayHasKey('new_value', $activity);
        $this->assertArrayHasKey('workflow_stage_id', $activity);
        $this->assertArrayHasKey('created_at', $activity);
    }
}
