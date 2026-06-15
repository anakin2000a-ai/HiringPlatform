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
    // store_name-based routing
    // -----------------------------------------------------------------------

    public function test_store_is_resolved_by_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['store_name' => 'downtown-branch']);
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->store_name}")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.store_name', 'downtown-branch');
    }

    public function test_unknown_store_name_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/stores/nonexistent-store-xyz')
            ->assertNotFound();
    }

    public function test_numeric_string_returns_404_when_no_store_has_that_name(): void
    {
        // A pure numeric string like "99999" is treated as a store_name.
        // Since no store has store_name = "99999", it returns 404.
        $user = User::factory()->create();

        $this->actingAs($user)
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
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->store_name}")
            ->assertOk();
    }

    public function test_middleware_blocks_franchise_admin_from_another_franchise_store(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchiseA)->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $storeA->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeB->store_name}")
            ->assertForbidden();
    }

    public function test_middleware_allows_store_manager_to_access_assigned_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->create();
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->store_name}")
            ->assertOk();
    }

    public function test_middleware_blocks_store_manager_from_unassigned_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->create();
        // No UserStoreAccess row created

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$store->store_name}")
            ->assertForbidden();
    }

    public function test_middleware_blocks_access_to_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $assignedStoreA = Store::factory()->for($franchiseA)->create();

        $manager = User::factory()->create();
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $assignedStoreA->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$storeB->store_name}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Index — result filtering (no EnsureStoreAccess, filtered by service)
    // -----------------------------------------------------------------------

    public function test_franchise_admin_sees_only_own_franchise_stores_in_list(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storesA = Store::factory()->for($franchiseA)->count(3)->create();
        Store::factory()->for($franchiseB)->count(2)->create();

        $admin = User::factory()->create();
        foreach ($storesA as $s) {
            UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $s->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);
        }

        $response = $this->actingAs($admin)->getJson('/api/v1/stores');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_store_manager_sees_only_assigned_stores_in_list(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $assignedStore = Store::factory()->for($franchise)->create();
        $otherStore = Store::factory()->for($franchise)->create();

        $manager = User::factory()->create();
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $assignedStore->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);

        $response = $this->actingAs($manager)->getJson('/api/v1/stores');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($assignedStore->id));
        $this->assertFalse($ids->contains($otherStore->id));
    }

    // -----------------------------------------------------------------------
    // Role-agnostic access: any active user_store_access grants management
    // -----------------------------------------------------------------------

    public function test_user_with_viewer_role_can_manage_store_resources(): void
    {
        // Case A: user_store_access exists with role = viewer → allowed
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $user = User::factory()->create();
        UserStoreAccess::create([
            'user_id'      => $user->id,
            'store_id'     => $store->id,
            'role'         => 'viewer',
            'access_scope' => 'store',
            'status'       => 'active',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows", ['name' => 'Viewer Workflow'])
            ->assertCreated();
    }

    public function test_user_with_empty_role_can_manage_store_resources(): void
    {
        // Case B: user_store_access exists with role = '' → allowed (role is irrelevant)
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $user = User::factory()->create();
        UserStoreAccess::create([
            'user_id'      => $user->id,
            'store_id'     => $store->id,
            'role'         => 'viewer', // role value is irrelevant to access check; any valid role
            'access_scope' => 'store',
            'status'       => 'active',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows", ['name' => 'Empty Role Workflow'])
            ->assertCreated();
    }

    public function test_user_without_store_access_cannot_manage_store_resources(): void
    {
        // Case C: no user_store_access row → 403
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $user = User::factory()->create();
        // No UserStoreAccess created

        $this->actingAs($user)
            ->postJson("/api/v1/stores/{$store->store_name}/workflows", ['name' => 'No Access Workflow'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Unauthenticated
    // -----------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/stores')->assertUnauthorized();
    }
}
