<?php

namespace Tests\Feature\Events;

use App\Models\FranchiseAccount;
use App\Models\InboxEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\AccessControl\StoreAccessService;
use App\Services\Events\InboxEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for auth.v1.assignment.user_role_store.* inbound events.
 *
 * No live NATS server is required — all assertions operate purely through
 * InboxEventProcessor / handler logic against the in-memory SQLite database.
 */
class AssignmentEventTest extends TestCase
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
    private function envelope(string $subject, array $data): array
    {
        return [
            'event_id'    => Str::uuid()->toString(),
            'event_type'  => $subject,
            'occurred_at' => '2026-01-01T00:00:00Z',
            'source'      => 'auth-service',
            'version'     => 1,
            'data'        => $data,
        ];
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeStore(): Store
    {
        $franchise = FranchiseAccount::factory()->create();
        return Store::factory()->for($franchise)->create();
    }

    // -----------------------------------------------------------------------
    // 1. assigned — creates user_store_access when user and store exist
    // -----------------------------------------------------------------------

    public function test_assigned_creates_access_row_when_user_and_store_exist(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.assigned',
            $this->envelope('auth.v1.assignment.user_role_store.assigned', [
                'user_id'  => $user->id,
                'store_id' => $store->id,
                'role_id'  => 5,
                'is_active' => true,
            ])
        );

        $this->assertDatabaseHas('user_store_access', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);

        $this->assertDatabaseHas('inbox_events', [
            'subject' => 'auth.v1.assignment.user_role_store.assigned',
            'status'  => 'processed',
        ]);
    }

    public function test_assigned_shape_a_nested_assignment_key(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.assigned',
            $this->envelope('auth.v1.assignment.user_role_store.assigned', [
                'assignment' => [
                    'user_id'  => $user->id,
                    'store_id' => $store->id,
                    'role_id'  => 7,
                    'is_active' => true,
                ],
                'metadata' => ['source' => 'import'],
            ])
        );

        $this->assertDatabaseHas('user_store_access', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. assigned — idempotent on duplicate
    // -----------------------------------------------------------------------

    public function test_assigned_is_idempotent_when_access_row_already_exists(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        UserStoreAccess::create(['user_id' => $user->id, 'store_id' => $store->id]);

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.assigned', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);

        $this->processor()->process('auth.v1.assignment.user_role_store.assigned', $envelope);

        $this->assertSame(1, UserStoreAccess::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->count());
    }

    // -----------------------------------------------------------------------
    // 3. assigned — fails retryably if user is missing
    // -----------------------------------------------------------------------

    public function test_assigned_fails_retryably_when_user_does_not_exist(): void
    {
        $store = $this->makeStore();

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.assigned', [
            'user_id'  => 999999,
            'store_id' => $store->id,
        ]);

        try {
            $this->processor()->process('auth.v1.assignment.user_role_store.assigned', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'failed',
        ]);

        $inbox = InboxEvent::where('event_id', $envelope['event_id'])->first();
        $this->assertStringContainsString('user_id', $inbox->last_error);
        $this->assertDatabaseCount('user_store_access', 0);
    }

    // -----------------------------------------------------------------------
    // 4. assigned — fails retryably if store is missing
    // -----------------------------------------------------------------------

    public function test_assigned_fails_retryably_when_store_does_not_exist(): void
    {
        $user = $this->makeUser();

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.assigned', [
            'user_id'  => $user->id,
            'store_id' => 999999,
        ]);

        try {
            $this->processor()->process('auth.v1.assignment.user_role_store.assigned', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'failed',
        ]);

        $inbox = InboxEvent::where('event_id', $envelope['event_id'])->first();
        $this->assertStringContainsString('store_id', $inbox->last_error);
        $this->assertDatabaseCount('user_store_access', 0);
    }

    // -----------------------------------------------------------------------
    // 5. removed — deletes only the access row
    // -----------------------------------------------------------------------

    public function test_removed_deletes_access_row_but_not_user_or_store(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();
        UserStoreAccess::create(['user_id' => $user->id, 'store_id' => $store->id]);

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.removed',
            $this->envelope('auth.v1.assignment.user_role_store.removed', [
                'user_id'  => $user->id,
                'store_id' => $store->id,
            ])
        );

        $this->assertDatabaseMissing('user_store_access', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);

        // User and store must not be deleted
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('stores', ['id' => $store->id]);

        $this->assertDatabaseHas('inbox_events', [
            'subject' => 'auth.v1.assignment.user_role_store.removed',
            'status'  => 'processed',
        ]);
    }

    // -----------------------------------------------------------------------
    // 6. removed — unknown access row is a no-op
    // -----------------------------------------------------------------------

    public function test_removed_is_noop_when_access_row_does_not_exist(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $result = $this->processor()->process(
            'auth.v1.assignment.user_role_store.removed',
            $this->envelope('auth.v1.assignment.user_role_store.removed', [
                'user_id'  => $user->id,
                'store_id' => $store->id,
            ])
        );

        $this->assertSame('processed', $result->status);
        $this->assertDatabaseCount('user_store_access', 0);
    }

    // -----------------------------------------------------------------------
    // 7. toggled false — removes access
    // -----------------------------------------------------------------------

    public function test_toggled_false_removes_access_row(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();
        UserStoreAccess::create(['user_id' => $user->id, 'store_id' => $store->id]);

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.toggled',
            $this->envelope('auth.v1.assignment.user_role_store.toggled', [
                'user_id'        => $user->id,
                'store_id'       => $store->id,
                'before_is_active' => true,
                'after_is_active'  => false,
            ])
        );

        $this->assertDatabaseMissing('user_store_access', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_toggled_false_is_noop_when_access_row_already_absent(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $result = $this->processor()->process(
            'auth.v1.assignment.user_role_store.toggled',
            $this->envelope('auth.v1.assignment.user_role_store.toggled', [
                'user_id'        => $user->id,
                'store_id'       => $store->id,
                'after_is_active' => false,
            ])
        );

        $this->assertSame('processed', $result->status);
        $this->assertDatabaseCount('user_store_access', 0);
    }

    // -----------------------------------------------------------------------
    // 8. toggled true — creates/reactivates access
    // -----------------------------------------------------------------------

    public function test_toggled_true_creates_access_when_user_and_store_exist(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.toggled',
            $this->envelope('auth.v1.assignment.user_role_store.toggled', [
                'user_id'        => $user->id,
                'store_id'       => $store->id,
                'after_is_active' => true,
            ])
        );

        $this->assertDatabaseHas('user_store_access', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_toggled_true_is_noop_when_access_row_already_exists(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();
        UserStoreAccess::create(['user_id' => $user->id, 'store_id' => $store->id]);

        $result = $this->processor()->process(
            'auth.v1.assignment.user_role_store.toggled',
            $this->envelope('auth.v1.assignment.user_role_store.toggled', [
                'user_id'        => $user->id,
                'store_id'       => $store->id,
                'after_is_active' => true,
            ])
        );

        $this->assertSame('processed', $result->status);
        $this->assertSame(1, UserStoreAccess::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->count());
    }

    public function test_toggled_true_fails_retryably_when_user_missing(): void
    {
        $store = $this->makeStore();

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.toggled', [
            'user_id'        => 999999,
            'store_id'       => $store->id,
            'after_is_active' => true,
        ]);

        try {
            $this->processor()->process('auth.v1.assignment.user_role_store.toggled', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'failed',
        ]);

        $this->assertDatabaseCount('user_store_access', 0);
    }

    // -----------------------------------------------------------------------
    // 9. bulk_assigned — creates multiple access rows
    // -----------------------------------------------------------------------

    public function test_bulk_assigned_creates_multiple_access_rows(): void
    {
        $user1  = $this->makeUser();
        $user2  = $this->makeUser();
        $store1 = $this->makeStore();
        $store2 = $this->makeStore();

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.bulk_assigned',
            $this->envelope('auth.v1.assignment.user_role_store.bulk_assigned', [
                'assignments' => [
                    ['user_id' => $user1->id, 'store_id' => $store1->id],
                    ['user_id' => $user2->id, 'store_id' => $store2->id],
                ],
            ])
        );

        $this->assertDatabaseHas('user_store_access', ['user_id' => $user1->id, 'store_id' => $store1->id]);
        $this->assertDatabaseHas('user_store_access', ['user_id' => $user2->id, 'store_id' => $store2->id]);
        $this->assertSame(2, UserStoreAccess::count());
    }

    public function test_bulk_assigned_supports_shape_a_nested_assignment_key(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.bulk_assigned',
            $this->envelope('auth.v1.assignment.user_role_store.bulk_assigned', [
                'assignments' => [
                    ['assignment' => ['user_id' => $user->id, 'store_id' => $store->id]],
                ],
            ])
        );

        $this->assertDatabaseHas('user_store_access', ['user_id' => $user->id, 'store_id' => $store->id]);
    }

    // -----------------------------------------------------------------------
    // 10. bulk_assigned — atomic: fails retryably without partial writes
    // -----------------------------------------------------------------------

    public function test_bulk_assigned_fails_atomically_when_one_user_is_missing(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.bulk_assigned', [
            'assignments' => [
                ['user_id' => $user->id,  'store_id' => $store->id],
                ['user_id' => 999999,     'store_id' => $store->id], // missing user
            ],
        ]);

        try {
            $this->processor()->process('auth.v1.assignment.user_role_store.bulk_assigned', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'failed',
        ]);

        // No partial rows written
        $this->assertDatabaseCount('user_store_access', 0);
    }

    public function test_bulk_assigned_fails_atomically_when_one_store_is_missing(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.bulk_assigned', [
            'assignments' => [
                ['user_id' => $user->id, 'store_id' => $store->id],
                ['user_id' => $user->id, 'store_id' => 999999],     // missing store
            ],
        ]);

        try {
            $this->processor()->process('auth.v1.assignment.user_role_store.bulk_assigned', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseCount('user_store_access', 0);
    }

    // -----------------------------------------------------------------------
    // 11. Duplicate event_id is skipped idempotently
    // -----------------------------------------------------------------------

    public function test_duplicate_event_id_is_skipped_idempotently(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $envelope = $this->envelope('auth.v1.assignment.user_role_store.assigned', [
            'user_id'  => $user->id,
            'store_id' => $store->id,
        ]);

        $this->processor()->process('auth.v1.assignment.user_role_store.assigned', $envelope);
        $this->processor()->process('auth.v1.assignment.user_role_store.assigned', $envelope);

        $this->assertSame(1, InboxEvent::where('event_id', $envelope['event_id'])->count());
        $this->assertSame(1, UserStoreAccess::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->count());
    }

    // -----------------------------------------------------------------------
    // 12. StoreAccessService recognizes access created by assignment events
    // -----------------------------------------------------------------------

    public function test_store_access_service_recognizes_access_created_by_assignment_event(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();

        $service = new StoreAccessService();

        $this->assertFalse($service->canAccessStore($user, $store));

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.assigned',
            $this->envelope('auth.v1.assignment.user_role_store.assigned', [
                'user_id'  => $user->id,
                'store_id' => $store->id,
            ])
        );

        $user->refresh();
        $this->assertTrue($service->canAccessStore($user, $store));
    }

    public function test_store_access_service_denies_access_after_removed_event(): void
    {
        $user  = $this->makeUser();
        $store = $this->makeStore();
        UserStoreAccess::create(['user_id' => $user->id, 'store_id' => $store->id]);

        $service = new StoreAccessService();
        $this->assertTrue($service->canAccessStore($user, $store));

        $this->processor()->process(
            'auth.v1.assignment.user_role_store.removed',
            $this->envelope('auth.v1.assignment.user_role_store.removed', [
                'user_id'  => $user->id,
                'store_id' => $store->id,
            ])
        );

        $user->refresh();
        $this->assertFalse($service->canAccessStore($user, $store));
    }

    // -----------------------------------------------------------------------
    // 13. No live NATS server required
    // -----------------------------------------------------------------------

    public function test_no_real_nats_connection_is_made_during_assignment_tests(): void
    {
        $binding = $this->app->make(\App\Services\Events\EventBusPublisher::class);

        $this->assertInstanceOf(
            \App\Services\Events\LogEventBusPublisher::class,
            $binding
        );
    }
}
