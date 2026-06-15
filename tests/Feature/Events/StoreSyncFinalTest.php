<?php

namespace Tests\Feature\Events;

use App\Models\FranchiseAccount;
use App\Models\Store;
use App\Services\Events\InboxEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreSyncFinalTest extends TestCase
{
    use RefreshDatabase;

    private function processor(): InboxEventProcessor
    {
        return new InboxEventProcessor();
    }

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
    // auth.v1.store.created
    // -----------------------------------------------------------------------

    public function test_store_created_sets_status_active_on_new_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();

        $this->processor()->process(
            'auth.v1.store.created',
            $this->envelope('auth.v1.store.created', [
                'id'                   => 7001,
                'store_name'           => 'New Branch',
                'franchise_account_id' => $franchise->id,
            ])
        );

        $this->assertDatabaseHas('stores', [
            'id'     => 7001,
            'status' => 'active',
        ]);
    }

    public function test_store_created_reactivates_existing_inactive_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create([
            'store_name' => 'Old Name',
            'status'     => 'inactive',
        ]);

        $this->processor()->process(
            'auth.v1.store.created',
            $this->envelope('auth.v1.store.created', [
                'id'                   => $store->id,
                'store_name'           => 'Reactivated Name',
                'franchise_account_id' => $franchise->id,
            ])
        );

        $store->refresh();
        $this->assertSame('active', $store->status instanceof \BackedEnum ? $store->status->value : $store->status);
        $this->assertSame('Reactivated Name', $store->store_name);
    }

    // -----------------------------------------------------------------------
    // auth.v1.store.updated
    // -----------------------------------------------------------------------

    public function test_store_updated_updates_store_name(): void
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

    public function test_store_updated_accepts_allowlisted_status_values(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['status' => 'active']);

        $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', [
                'id'     => $store->id,
                'status' => 'inactive',
            ])
        );

        $store->refresh();
        $this->assertSame('inactive', $store->status instanceof \BackedEnum ? $store->status->value : $store->status);
    }

    public function test_store_updated_ignores_arbitrary_status_values(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['status' => 'active']);

        $this->processor()->process(
            'auth.v1.store.updated',
            $this->envelope('auth.v1.store.updated', [
                'id'     => $store->id,
                'status' => 'hacked',
            ])
        );

        $store->refresh();
        $this->assertSame('active', $store->status instanceof \BackedEnum ? $store->status->value : $store->status);
    }

    // -----------------------------------------------------------------------
    // auth.v1.store.deleted
    // -----------------------------------------------------------------------

    public function test_store_deleted_sets_status_inactive(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['status' => 'active']);

        $this->processor()->process(
            'auth.v1.store.deleted',
            $this->envelope('auth.v1.store.deleted', ['id' => $store->id])
        );

        $store->refresh();
        $this->assertSame('inactive', $store->status instanceof \BackedEnum ? $store->status->value : $store->status);
    }

    public function test_store_deleted_does_not_physically_delete_the_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();

        $this->processor()->process(
            'auth.v1.store.deleted',
            $this->envelope('auth.v1.store.deleted', ['id' => $store->id])
        );

        $this->assertDatabaseHas('stores', ['id' => $store->id]);
    }

    public function test_store_deleted_is_noop_when_store_not_found(): void
    {
        $result = $this->processor()->process(
            'auth.v1.store.deleted',
            $this->envelope('auth.v1.store.deleted', ['id' => 999999])
        );

        $this->assertSame('processed', $result->status instanceof \BackedEnum ? $result->status->value : $result->status);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_store_deleted_duplicate_event_id_is_idempotently_skipped(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['status' => 'active']);

        $envelope = $this->envelope('auth.v1.store.deleted', ['id' => $store->id]);

        $this->processor()->process('auth.v1.store.deleted', $envelope);
        $result2 = $this->processor()->process('auth.v1.store.deleted', $envelope);

        $this->assertSame('processed', $result2->status instanceof \BackedEnum ? $result2->status->value : $result2->status);
        $this->assertSame(
            1,
            \App\Models\InboxEvent::where('event_id', $envelope['event_id'])->count()
        );
        // Status remains inactive from first processing; second call was a no-op
        $store->refresh();
        $this->assertSame('inactive', $store->status instanceof \BackedEnum ? $store->status->value : $store->status);
    }

    // -----------------------------------------------------------------------
    // Fix 1 verification — NATS env name
    // -----------------------------------------------------------------------

    public function test_nats_config_uses_hiring_platform_stream_env_name(): void
    {
        $config = config('nats');

        $this->assertArrayHasKey('jetstream', $config);
        $this->assertArrayHasKey('stream', $config['jetstream']);

        // Default value matches the new naming convention
        $this->assertSame('HIRING_PLATFORM_EVENTS', $config['jetstream']['stream']);
    }
}
