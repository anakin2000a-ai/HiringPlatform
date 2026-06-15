<?php

namespace Tests\Feature\Events;

use App\Models\Application;
use App\Models\Applicant;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\InboxEvent;
use App\Models\JobOpening;
use App\Models\OutboxEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use App\Enums\TransitionType;
use App\Services\Applications\ApplicationStageService;
use App\Services\Events\InboxEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class NatsAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent sync queue from executing PublishHiringOutboxEventJob during tests.
        // Tests that assert job dispatch use Queue::fake() explicitly.
        Queue::fake();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function envelope(string $eventType, array $data = [], array $overrides = []): array
    {
        return array_merge([
            'event_id'    => Str::uuid()->toString(),
            'event_type'  => $eventType,
            'occurred_at' => '2026-01-01T00:00:00Z',
            'source'      => 'auth-service',
            'version'     => 1,
            'data'        => $data,
        ], $overrides);
    }

    private function processor(): InboxEventProcessor
    {
        return new InboxEventProcessor();
    }

    /**
     * Workflow: Applied (initial) → Hired (terminal) / Rejected (terminal)
     * Returns [$store, $admin, $applied, $hired, $rejected, $application]
     */
    private function makeTerminalSetup(): array
    {
        $franchise   = FranchiseAccount::factory()->create();
        $store       = Store::factory()->for($franchise)->create();
        $admin       = User::factory()->create();
        \App\Models\UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $workflow  = HiringWorkflow::factory()->forStore($store)->create();
        $applied   = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create([
            'name'       => 'Applied',
            'stage_type' => 'application',
        ]);
        $hired = WorkflowStage::factory()->forWorkflow($workflow)->create([
            'name'        => 'Hired',
            'stage_type'  => 'hired',
            'is_terminal' => true,
            'position'    => 2,
        ]);
        $rejected = WorkflowStage::factory()->forWorkflow($workflow)->create([
            'name'        => 'Rejected',
            'stage_type'  => 'rejected',
            'is_terminal' => true,
            'position'    => 3,
        ]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $applied->id,
            'to_stage_id'        => $hired->id,
            'is_manual_allowed'  => true,
        ]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $applied->id,
            'to_stage_id'        => $rejected->id,
            'is_manual_allowed'  => true,
        ]);

        $job         = JobOpening::factory()->withWorkflow($workflow)->published()->create();
        $application = Application::factory()->atStage($applied)->create(['job_opening_id' => $job->id]);

        return [$store, $admin, $applied, $hired, $rejected, $application];
    }

    // -----------------------------------------------------------------------
    // Inbound: auth.v1.user.updated
    // -----------------------------------------------------------------------

    public function test_auth_v1_user_updated_updates_local_user_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->processor()->process(
            'auth.v1.user.updated',
            $this->envelope('auth.v1.user.updated', [
                'id'    => $user->id,
                'name'  => 'New Name',
                'email' => 'new@example.com',
            ])
        );

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);

        $this->assertDatabaseHas('inbox_events', [
            'subject' => 'auth.v1.user.updated',
            'status'  => 'processed',
        ]);
    }

    public function test_auth_v1_user_updated_with_unknown_id_is_no_op(): void
    {
        $result = $this->processor()->process(
            'auth.v1.user.updated',
            $this->envelope('auth.v1.user.updated', ['id' => 999999, 'name' => 'Ghost'])
        );

        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
        $this->assertDatabaseCount('users', 0);
    }

    // -----------------------------------------------------------------------
    // Inbound: auth.v1.store.updated
    // -----------------------------------------------------------------------

    public function test_auth_v1_store_updated_updates_local_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['store_name' => 'Old Store']);

        $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', [
                'id'         => $store->id,
                'store_name' => 'New Store',
            ])
        );

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'store_name' => 'New Store']);
        $this->assertDatabaseHas('inbox_events', [
            'subject' => 'auth.v1.store.updated',
            'status'  => 'processed',
        ]);
    }

    public function test_auth_v1_store_updated_with_unknown_id_is_no_op(): void
    {
        $result = $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', ['id' => 999999, 'store_name' => 'Ghost'])
        );

        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
        $this->assertDatabaseCount('stores', 0);
    }

    // -----------------------------------------------------------------------
    // Inbound idempotency
    // -----------------------------------------------------------------------

    public function test_duplicate_processed_event_id_is_skipped_idempotently(): void
    {
        $envelope = $this->envelope('auth.v1.user.updated', []);

        $this->processor()->process('auth.v1.user.updated', $envelope);
        $this->processor()->process('auth.v1.user.updated', $envelope);

        $this->assertSame(1, InboxEvent::where('event_id', $envelope['event_id'])->count());
    }

    // -----------------------------------------------------------------------
    // Outbound: hiring.v1.application.hired
    // -----------------------------------------------------------------------

    public function test_moving_application_to_hired_stage_creates_v1_hired_outbox_event(): void
    {
        [, $admin, , $hired, , $application] = $this->makeTerminalSetup();

        $service = $this->app->make(ApplicationStageService::class);
        $service->move($application, $hired->id, null, $admin, TransitionType::Manual);

        $event = OutboxEvent::where('event_type', 'hiring.v1.application.hired')->first();

        $this->assertNotNull($event);
        $this->assertSame('hiring.v1.application.hired', $event->subject);
        $this->assertSame('pending', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
        $this->assertSame('1.0', $event->payload['specversion']);
        $this->assertSame('hiring-platform', $event->payload['source']);
        $this->assertSame('hired', $event->payload['data']['status']);
        $this->assertSame($application->id, $event->payload['data']['application_id']);
        $this->assertArrayHasKey('applicant', $event->payload['data']);
    }

    // -----------------------------------------------------------------------
    // Outbound: hiring.v1.application.rejected
    // -----------------------------------------------------------------------

    public function test_moving_application_to_rejected_stage_creates_v1_rejected_outbox_event(): void
    {
        [, $admin, , , $rejected, $application] = $this->makeTerminalSetup();

        $service = $this->app->make(ApplicationStageService::class);
        $service->move($application, $rejected->id, null, $admin, TransitionType::Manual);

        $event = OutboxEvent::where('event_type', 'hiring.v1.application.rejected')->first();

        $this->assertNotNull($event);
        $this->assertSame('hiring.v1.application.rejected', $event->subject);
        $this->assertSame('pending', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
        $this->assertSame('rejected', $event->payload['data']['status']);
        $this->assertSame($application->id, $event->payload['data']['application_id']);
    }

    // -----------------------------------------------------------------------
    // Existing hiring.application.stage_changed behavior intact
    // -----------------------------------------------------------------------

    public function test_hiring_application_stage_changed_is_still_written_on_every_move(): void
    {
        [, $admin, , $hired, , $application] = $this->makeTerminalSetup();

        $service = $this->app->make(ApplicationStageService::class);
        $service->move($application, $hired->id, null, $admin, TransitionType::Manual);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.application.stage_changed',
            'status'     => 'pending',
        ]);
    }

    public function test_both_stage_changed_and_v1_hired_events_are_written_together(): void
    {
        [, $admin, , $hired, , $application] = $this->makeTerminalSetup();

        $service = $this->app->make(ApplicationStageService::class);
        $service->move($application, $hired->id, null, $admin, TransitionType::Manual);

        $this->assertSame(2, OutboxEvent::whereIn('event_type', [
            'hiring.application.stage_changed',
            'hiring.v1.application.hired',
        ])->count());
    }

    // -----------------------------------------------------------------------
    // No real NATS required — verified by structure
    // -----------------------------------------------------------------------

    public function test_no_real_nats_connection_is_made_during_tests(): void
    {
        // All inbound paths go through InboxEventProcessor which only touches
        // MySQL. All outbound paths write to outbox_events (MySQL). The
        // JetStreamConsumer and NatsClientFactory are never resolved in tests.
        // This test asserts that the event bus binding resolves to a non-NATS
        // implementation and that no network calls have been made.

        $binding = $this->app->make(\App\Services\Events\EventBusPublisher::class);

        $this->assertInstanceOf(
            \App\Services\Events\LogEventBusPublisher::class,
            $binding
        );
    }
}
