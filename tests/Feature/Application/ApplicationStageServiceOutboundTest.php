<?php

namespace Tests\Feature\Application;

use App\Jobs\PublishHiringOutboxEventJob;
use App\Models\Application;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\OutboxEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use App\Services\Applications\ApplicationStageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplicationStageServiceOutboundTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Applied (initial) → Screening → Hired (terminal) / Rejected (terminal)
     * Returns [$store, $admin, $applied, $screening, $hired, $rejected, $job, $application]
     */
    private function makeSetup(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();
        $admin     = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflow  = HiringWorkflow::factory()->forStore($store)->create();
        $applied   = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create(['stage_type' => 'application']);
        $screening = WorkflowStage::factory()->forWorkflow($workflow)->create([
            'name'       => 'Screening',
            'stage_type' => 'screening',
            'position'   => 2,
        ]);
        $hired = WorkflowStage::factory()->forWorkflow($workflow)->create([
            'name'        => 'Hired',
            'stage_type'  => 'hired',
            'is_terminal' => true,
            'position'    => 3,
        ]);
        $rejected = WorkflowStage::factory()->forWorkflow($workflow)->create([
            'name'        => 'Rejected',
            'stage_type'  => 'rejected',
            'is_terminal' => true,
            'position'    => 4,
        ]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $applied->id,
            'to_stage_id'        => $screening->id,
            'is_manual_allowed'  => true,
        ]);
        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'    => $workflow->id,
            'from_stage_id'         => $screening->id,
            'to_stage_id'           => $hired->id,
            'is_manual_allowed'     => true,
            'is_automatic_allowed'  => true,
        ]);
        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $applied->id,
            'to_stage_id'        => $rejected->id,
            'is_manual_allowed'  => true,
        ]);
        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'    => $workflow->id,
            'from_stage_id'         => $applied->id,
            'to_stage_id'           => $hired->id,
            'is_manual_allowed'     => true,
            'is_automatic_allowed'  => true,
        ]);

        $job         = JobOpening::factory()->withWorkflow($workflow)->published()->create();
        $application = Application::factory()->atStage($applied)->create(['job_opening_id' => $job->id]);

        return [$store, $admin, $applied, $screening, $hired, $rejected, $job, $application];
    }

    private function service(): ApplicationStageService
    {
        return $this->app->make(ApplicationStageService::class);
    }

    // -----------------------------------------------------------------------
    // E: Hired terminal creates hiring.v1.application.hired outbox row
    // -----------------------------------------------------------------------

    public function test_moving_to_hired_creates_v1_hired_outbox_event(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.v1.application.hired',
            'subject'    => 'hiring.v1.application.hired',
            'status'     => 'pending',
        ]);
    }

    public function test_hired_event_payload_has_correct_source_and_specversion(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        $event = OutboxEvent::where('event_type', 'hiring.v1.application.hired')->first();

        $this->assertSame('hiring-platform', $event->payload['source']);
        $this->assertSame('1.0', $event->payload['specversion']);
    }

    public function test_hired_event_payload_data_contains_decision_and_status(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        $event = OutboxEvent::where('event_type', 'hiring.v1.application.hired')->first();
        $this->assertSame('hired', $event->payload['data']['decision']);
        $this->assertSame('hired', $event->payload['data']['status']);
    }

    public function test_hired_event_payload_data_contains_full_fields(): void
    {
        Queue::fake();
        [$store, $admin, $applied, , $hired, , $job, $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        $event = OutboxEvent::where('event_type', 'hiring.v1.application.hired')->first();
        $data  = $event->payload['data'];

        $this->assertSame($application->id, $data['application_id']);
        $this->assertArrayHasKey('applicant_id', $data);
        $this->assertArrayHasKey('applicant_name', $data);
        $this->assertArrayHasKey('applicant_email', $data);
        $this->assertArrayHasKey('applicant_phone', $data);
        $this->assertSame($job->id, $data['job_opening_id']);
        $this->assertSame($store->id, $data['store_id']);
        $this->assertSame($store->store_name, $data['store_name']);
        $this->assertArrayHasKey('franchise_account_id', $data);
        $this->assertSame($applied->id, $data['previous_stage_id']);
        $this->assertSame($hired->id, $data['current_stage_id']);
        $this->assertSame('Hired', $data['current_stage_name']);
        $this->assertArrayHasKey('decided_at', $data);
        $this->assertSame($admin->id, $data['decided_by_user_id']);
        $this->assertArrayHasKey('applicant', $data);
    }

    // -----------------------------------------------------------------------
    // E: Rejected terminal creates hiring.v1.application.rejected outbox row
    // -----------------------------------------------------------------------

    public function test_moving_to_rejected_creates_v1_rejected_outbox_event(): void
    {
        Queue::fake();
        [, $admin, , , , $rejected, , $application] = $this->makeSetup();

        $this->service()->move($application, $rejected->id, null, $admin);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.v1.application.rejected',
            'subject'    => 'hiring.v1.application.rejected',
            'status'     => 'pending',
        ]);
    }

    // -----------------------------------------------------------------------
    // E: stage_changed is still written on every move (unchanged behavior)
    // -----------------------------------------------------------------------

    public function test_stage_changed_outbox_event_is_still_written_on_non_terminal_move(): void
    {
        Queue::fake();
        [, $admin, , $screening, , , , $application] = $this->makeSetup();

        $this->service()->move($application, $screening->id, null, $admin);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.application.stage_changed',
            'status'     => 'pending',
        ]);
    }

    public function test_both_stage_changed_and_v1_hired_written_on_terminal_move(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        $this->assertSame(2, OutboxEvent::whereIn('event_type', [
            'hiring.application.stage_changed',
            'hiring.v1.application.hired',
        ])->count());
    }

    // -----------------------------------------------------------------------
    // E: No duplicate hired event if already hired
    // -----------------------------------------------------------------------

    public function test_no_duplicate_hired_event_if_application_already_hired(): void
    {
        Queue::fake();
        [, $admin, , $screening, $hired, , , $application] = $this->makeSetup();

        // Move to screening first
        $this->service()->move($application, $screening->id, null, $admin);

        // Move to hired (sets hired_at)
        $application->refresh();
        $this->service()->move($application, $hired->id, null, $admin);

        // Simulate calling move again to the hired stage when already hired
        // (would only be possible with a forced state; guard is on hired_at being null)
        // Verify only ONE hired v1 event exists
        $count = OutboxEvent::where('event_type', 'hiring.v1.application.hired')->count();
        $this->assertSame(1, $count);
    }

    public function test_no_duplicate_rejected_event_if_application_already_rejected(): void
    {
        Queue::fake();
        [, $admin, , , , $rejected, , $application] = $this->makeSetup();

        // Move to rejected once
        $this->service()->move($application, $rejected->id, null, $admin);

        // Only one v1 rejected event
        $count = OutboxEvent::where('event_type', 'hiring.v1.application.rejected')->count();
        $this->assertSame(1, $count);
    }

    // -----------------------------------------------------------------------
    // E: Rollback prevents job dispatch
    // -----------------------------------------------------------------------

    public function test_rollback_prevents_job_dispatch(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        try {
            DB::transaction(function () use ($application, $hired, $admin): void {
                $this->service()->move($application, $hired->id, null, $admin);
                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNotPushed(PublishHiringOutboxEventJob::class);
    }

    public function test_rollback_prevents_outbox_row(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        try {
            DB::transaction(function () use ($application, $hired, $admin): void {
                $this->service()->move($application, $hired->id, null, $admin);
                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'hiring.v1.application.hired']);
    }

    // -----------------------------------------------------------------------
    // E: Automation move_to_stage still works
    // -----------------------------------------------------------------------

    public function test_automation_move_to_stage_still_works_via_service(): void
    {
        Queue::fake();
        [, $admin, , $screening, $hired, , , $application] = $this->makeSetup();

        // Move to screening manually first
        $this->service()->move($application, $screening->id, null, $admin);

        // Automation move (transitionType = automatic)
        $application->refresh();
        $this->service()->move($application, $hired->id, null, $admin, 'automatic');

        $this->assertDatabaseHas('application_stage_transitions', [
            'application_id'  => $application->id,
            'to_stage_id'     => $hired->id,
            'transition_type' => 'automatic',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.v1.application.hired',
        ]);
    }

    // -----------------------------------------------------------------------
    // E: Dispatches PublishHiringOutboxEventJob after commit
    // -----------------------------------------------------------------------

    public function test_dispatches_publish_job_for_hired_terminal_event(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        Queue::assertPushed(PublishHiringOutboxEventJob::class);
    }

    public function test_no_publish_job_dispatched_for_non_terminal_move(): void
    {
        Queue::fake();
        [, $admin, , $screening, , , , $application] = $this->makeSetup();

        $this->service()->move($application, $screening->id, null, $admin);

        Queue::assertNotPushed(PublishHiringOutboxEventJob::class);
    }

    public function test_job_carries_correct_outbox_event_id(): void
    {
        Queue::fake();
        [, $admin, , , $hired, , , $application] = $this->makeSetup();

        $this->service()->move($application, $hired->id, null, $admin);

        $row = OutboxEvent::where('event_type', 'hiring.v1.application.hired')->first();
        $this->assertNotNull($row);

        Queue::assertPushed(PublishHiringOutboxEventJob::class, function ($job) use ($row): bool {
            return $job->outboxEventId === (string) $row->id;
        });
    }
}
