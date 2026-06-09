<?php

namespace Tests\Feature\Store;

use App\Models\FranchiseAccount;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAccessTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // ID-based routing
    // -----------------------------------------------------------------------

    public function test_store_is_resolved_by_id(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['store_name' => 'Downtown Branch']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.store_name', 'Downtown Branch');
    }

    public function test_unknown_id_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/stores/99999')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // EnsureStoreAccess middleware
    // -----------------------------------------------------------------------

    public function test_middleware_allows_franchise_admin_to_access_own_franchise_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}")
            ->assertOk();
    }

    public function test_middleware_blocks_franchise_admin_from_another_franchise_store(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseA->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeB->id}")
            ->assertForbidden();
    }

    public function test_middleware_allows_store_manager_to_access_assigned_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id]);

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->id}")
            ->assertOk();
    }

    public function test_middleware_blocks_store_manager_from_unassigned_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess row created

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->id}")
            ->assertForbidden();
    }

    public function test_middleware_blocks_access_to_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $assignedStoreA = Store::factory()->for($franchiseA)->create();

        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchiseA->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $assignedStoreA->id]);

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$storeB->id}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Index — result filtering (no EnsureStoreAccess, filtered by service)
    // -----------------------------------------------------------------------

    public function test_franchise_admin_sees_only_own_franchise_stores_in_list(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        Store::factory()->for($franchiseA)->count(3)->create();
        Store::factory()->for($franchiseB)->count(2)->create();

        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseA->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/stores');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_store_manager_sees_only_assigned_stores_in_list(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $assignedStore = Store::factory()->for($franchise)->create();
        $otherStore = Store::factory()->for($franchise)->create();

        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $assignedStore->id]);

        $response = $this->actingAs($manager)->getJson('/api/v1/stores');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($assignedStore->id));
        $this->assertFalse($ids->contains($otherStore->id));
    }

    // -----------------------------------------------------------------------
    // Unauthenticated
    // -----------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/stores')->assertUnauthorized();
    }
}
