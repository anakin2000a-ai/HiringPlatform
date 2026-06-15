<?php

namespace Tests\Feature\Events;

use App\Models\FranchiseAccount;
use App\Models\InboxEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\Events\InboxEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NatsSyncTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function processor(): InboxEventProcessor
    {
        return new InboxEventProcessor();
    }

    /** @param array<string, mixed> $data */
    private function envelope(string $eventType, array $data = []): array
    {
        return [
            'event_id'    => Str::uuid()->toString(),
            'event_type'  => $eventType,
            'occurred_at' => '2026-01-01T00:00:00Z',
            'source'      => 'auth-service',
            'version'     => 1,
            'data'        => $data,
        ];
    }

    // -----------------------------------------------------------------------
    // auth.v1.user.created
    // -----------------------------------------------------------------------

    public function test_user_created_event_creates_local_user(): void
    {
        $externalId = 5001;

        $this->processor()->process(
            'auth.v1.user.created',
            $this->envelope('auth.v1.user.created', [
                'id'    => $externalId,
                'name'  => 'Alice Smith',
                'email' => 'alice@example.com',
                'role'  => 'recruiter',
            ])
        );

        $this->assertDatabaseHas('users', [
            'id'    => $externalId,
            'name'  => 'Alice Smith',
            'email' => 'alice@example.com',
        ]);

        $this->assertDatabaseHas('inbox_events', [
            'subject' => 'auth.v1.user.created',
            'status'  => 'processed',
        ]);
    }

    public function test_user_created_event_updates_existing_user_with_same_id(): void
    {
        $user = User::factory()->create([
            'name'  => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $this->processor()->process(
            'auth.v1.user.created',
            $this->envelope('auth.v1.user.created', [
                'id'    => $user->id,
                'name'  => 'New Name',
                'email' => 'new@example.com',
                'role'  => 'store_manager',
            ])
        );

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_user_created_event_with_unknown_role_still_creates_user(): void
    {
        $externalId = 5002;

        $this->processor()->process(
            'auth.v1.user.created',
            $this->envelope('auth.v1.user.created', [
                'id'    => $externalId,
                'name'  => 'Bob',
                'email' => 'bob@example.com',
                'role'  => 'super_hacker',
            ])
        );

        $this->assertDatabaseHas('users', [
            'id'   => $externalId,
            'name' => 'Bob',
        ]);
    }

    public function test_user_created_event_is_noop_when_name_or_email_missing(): void
    {
        $this->processor()->process(
            'auth.v1.user.created',
            $this->envelope('auth.v1.user.created', ['id' => 5003])
        );

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('inbox_events', ['status' => 'processed']);
    }

    // -----------------------------------------------------------------------
    // auth.v1.user.updated
    // -----------------------------------------------------------------------

    public function test_user_updated_event_updates_existing_user(): void
    {
        $user = User::factory()->create(['name' => 'Before', 'email' => 'before@x.com']);

        $this->processor()->process(
            'auth.v1.user.updated',
            $this->envelope('auth.v1.user.updated', [
                'id'    => $user->id,
                'name'  => 'After',
                'email' => 'after@x.com',
            ])
        );

        $user->refresh();
        $this->assertSame('After', $user->name);
        $this->assertSame('after@x.com', $user->email);
    }

    public function test_user_updated_event_creates_user_when_not_found_with_min_fields(): void
    {
        $externalId = 6001;

        $this->processor()->process(
            'auth.v1.user.updated',
            $this->envelope('auth.v1.user.updated', [
                'id'    => $externalId,
                'name'  => 'Created Via Update',
                'email' => 'via-update@example.com',
            ])
        );

        $this->assertDatabaseHas('users', [
            'id'    => $externalId,
            'name'  => 'Created Via Update',
            'email' => 'via-update@example.com',
        ]);
    }

    public function test_user_updated_event_is_noop_when_not_found_and_email_missing(): void
    {
        $this->processor()->process(
            'auth.v1.user.updated',
            $this->envelope('auth.v1.user.updated', ['id' => 999999, 'name' => 'Ghost'])
        );

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('inbox_events', ['status' => 'processed']);
    }

    // -----------------------------------------------------------------------
    // auth.v1.user.deleted
    // -----------------------------------------------------------------------

    public function test_user_deleted_event_sets_status_inactive(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->processor()->process(
            'auth.v1.user.deleted',
            $this->envelope('auth.v1.user.deleted', ['id' => $user->id])
        );

        $user->refresh();
        $this->assertSame('inactive', $user->status instanceof \BackedEnum ? $user->status->value : $user->status);

        // User row still exists — no physical delete
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_user_deleted_event_is_noop_when_user_not_found(): void
    {
        $result = $this->processor()->process(
            'auth.v1.user.deleted',
            $this->envelope('auth.v1.user.deleted', ['id' => 999999])
        );

        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
        $this->assertDatabaseCount('users', 0);
    }

    // -----------------------------------------------------------------------
    // auth.v1.store.created
    // -----------------------------------------------------------------------

    public function test_store_created_event_creates_local_store_when_franchise_exists(): void
    {
        $franchise  = FranchiseAccount::factory()->create();
        $externalId = 8001;

        $this->processor()->process(
            'auth.v1.store.created',
            $this->envelope('auth.v1.store.created', [
                'id'                   => $externalId,
                'store_name'           => 'Downtown Branch',
                'franchise_account_id' => $franchise->id,
            ])
        );

        $this->assertDatabaseHas('stores', [
            'id'                   => $externalId,
            'store_name'           => 'Downtown Branch',
            'franchise_account_id' => $franchise->id,
        ]);

        $this->assertDatabaseHas('inbox_events', [
            'subject' => 'auth.v1.store.created',
            'status'  => 'processed',
        ]);
    }

    public function test_store_created_event_fails_when_franchise_account_id_missing(): void
    {
        $envelope = $this->envelope('auth.v1.store.created', [
            'id'         => 8002,
            'store_name' => 'Orphan Store',
            // franchise_account_id intentionally omitted
        ]);

        try {
            $this->processor()->process('auth.v1.store.created', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'failed',
        ]);

        $inbox = InboxEvent::where('event_id', $envelope['event_id'])->first();
        $this->assertNotNull($inbox->last_error);
        $this->assertStringContainsString('franchise_account_id', $inbox->last_error);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_store_created_event_fails_when_franchise_not_found_locally(): void
    {
        $envelope = $this->envelope('auth.v1.store.created', [
            'id'                   => 8003,
            'store_name'           => 'Ghost Store',
            'franchise_account_id' => 999999,
        ]);

        try {
            $this->processor()->process('auth.v1.store.created', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $inbox = InboxEvent::where('event_id', $envelope['event_id'])->first();
        $this->assertSame('failed', $inbox->status instanceof \BackedEnum ? $inbox->status->value : $inbox->status);
        $this->assertNotNull($inbox->last_error);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_store_created_event_updates_existing_store_with_same_id(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['store_name' => 'Old Name']);

        $this->processor()->process(
            'auth.v1.store.created',
            $this->envelope('auth.v1.store.created', [
                'id'                   => $store->id,
                'store_name'           => 'Updated Name',
                'franchise_account_id' => $franchise->id,
            ])
        );

        $this->assertDatabaseHas('stores', [
            'id'         => $store->id,
            'store_name' => 'Updated Name',
        ]);
    }

    // -----------------------------------------------------------------------
    // auth.v1.store.updated
    // -----------------------------------------------------------------------

    public function test_store_updated_event_updates_existing_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['store_name' => 'Before']);

        $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', [
                'id'         => $store->id,
                'store_name' => 'After',
            ])
        );

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'store_name' => 'After']);
    }

    public function test_store_updated_event_creates_store_when_not_found_with_required_fields(): void
    {
        $franchise  = FranchiseAccount::factory()->create();
        $externalId = 9001;

        $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', [
                'id'                   => $externalId,
                'store_name'           => 'New Branch',
                'franchise_account_id' => $franchise->id,
            ])
        );

        $this->assertDatabaseHas('stores', [
            'id'         => $externalId,
            'store_name' => 'New Branch',
        ]);
    }

    public function test_store_updated_event_is_noop_when_not_found_and_franchise_missing(): void
    {
        $result = $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', ['id' => 999999, 'store_name' => 'Ghost'])
        );

        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
        $this->assertDatabaseCount('stores', 0);
    }

    // -----------------------------------------------------------------------
    // auth.v1.store.deleted
    // -----------------------------------------------------------------------

    public function test_store_deleted_event_does_not_physically_delete_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();

        $result = $this->processor()->process(
            'auth.v1.store.deleted',
            $this->envelope('auth.v1.store.deleted', ['id' => $store->id])
        );

        // Store still exists — no safe deletion path without SoftDeletes or status column.
        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
    }

    public function test_store_deleted_event_is_noop_when_store_not_found(): void
    {
        $result = $this->processor()->process(
            'auth.v1.store.deleted',
            $this->envelope('auth.v1.store.deleted', ['id' => 999999])
        );

        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
    }

    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function test_duplicate_event_id_is_skipped_idempotently(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $envelope  = $this->envelope('auth.v1.store.updated', [
            'id'         => $franchise->id,
            'store_name' => 'Idempotency Store',
        ]);

        $this->processor()->process('auth.v1.store.updated', $envelope);
        $this->processor()->process('auth.v1.store.updated', $envelope);

        $this->assertSame(1, InboxEvent::where('event_id', $envelope['event_id'])->count());
    }

    // -----------------------------------------------------------------------
    // No real NATS required
    // -----------------------------------------------------------------------

    public function test_no_real_nats_connection_is_needed_for_sync_handlers(): void
    {
        // All handler paths are purely in-process MySQL operations.
        // JetStreamConsumer / NatsClientFactory are never touched.
        $binding = $this->app->make(\App\Services\Events\EventBusPublisher::class);

        $this->assertInstanceOf(
            \App\Services\Events\LogEventBusPublisher::class,
            $binding
        );
    }
}
