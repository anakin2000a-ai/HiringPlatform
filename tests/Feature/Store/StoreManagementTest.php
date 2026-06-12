<?php

namespace Tests\Feature\Store;

use App\Models\FranchiseAccount;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeFranchiseAdmin(FranchiseAccount $franchise, Store $anchorStore): User
    {
        $admin = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $anchorStore->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);
        return $admin;
    }

    private function makeStoreManager(Store $store): User
    {
        $manager = User::factory()->create();
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id, 'role' => 'store_manager', 'access_scope' => 'store', 'status' => 'active']);
        return $manager;
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_create_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $anchorStore = Store::factory()->for($franchise)->create();
        $admin = $this->makeFranchiseAdmin($franchise, $anchorStore);

        $response = $this->actingAs($admin)->postJson('/api/v1/stores', [
            'store_name' => 'Main Street Store',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.store_name', 'Main Street Store');

        $this->assertDatabaseHas('stores', [
            'store_name' => 'Main Street Store',
            'franchise_account_id' => $franchise->id,
        ]);
    }

    public function test_store_is_scoped_to_the_creating_admins_franchise(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $anchorStore = Store::factory()->for($franchise)->create();
        $admin = $this->makeFranchiseAdmin($franchise, $anchorStore);

        $this->actingAs($admin)->postJson('/api/v1/stores', ['store_name' => 'New Store']);

        $this->assertDatabaseHas('stores', ['franchise_account_id' => $franchise->id]);
    }

    public function test_store_manager_cannot_create_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = $this->makeStoreManager($store);

        $this->actingAs($manager)
            ->postJson('/api/v1/stores', ['store_name' => 'New Store'])
            ->assertForbidden();
    }

    public function test_create_store_requires_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $anchorStore = Store::factory()->for($franchise)->create();
        $admin = $this->makeFranchiseAdmin($franchise, $anchorStore);

        $this->actingAs($admin)
            ->postJson('/api/v1/stores', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['store_name']]);
    }

    // -----------------------------------------------------------------------
    // Show
    // -----------------------------------------------------------------------

    public function test_show_returns_store_by_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['store_name' => 'west-branch']);
        $admin = $this->makeFranchiseAdmin($franchise, $store);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->store_name}")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.store_name', 'west-branch');
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_update_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['store_name' => 'old-name']);
        $admin = $this->makeFranchiseAdmin($franchise, $store);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->store_name}", ['store_name' => 'new-name'])
            ->assertOk()
            ->assertJsonPath('data.store_name', 'new-name');

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'store_name' => 'new-name']);
    }

    public function test_store_manager_assigned_to_store_cannot_update_it(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = $this->makeStoreManager($store);

        $this->actingAs($manager)
            ->patchJson("/api/v1/stores/{$store->store_name}", ['store_name' => 'Hacked Name'])
            ->assertForbidden();
    }

    public function test_franchise_admin_cannot_update_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchiseA)->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $adminA = $this->makeFranchiseAdmin($franchiseA, $storeA);

        $this->actingAs($adminA)
            ->patchJson("/api/v1/stores/{$storeB->store_name}", ['store_name' => 'Hacked'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_delete_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = $this->makeFranchiseAdmin($franchise, $store);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->store_name}")
            ->assertOk();

        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_store_manager_assigned_to_store_cannot_delete_it(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = $this->makeStoreManager($store);

        $this->actingAs($manager)
            ->deleteJson("/api/v1/stores/{$store->store_name}")
            ->assertForbidden();
    }

    public function test_franchise_admin_cannot_delete_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeA = Store::factory()->for($franchiseA)->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $adminA = $this->makeFranchiseAdmin($franchiseA, $storeA);

        $this->actingAs($adminA)
            ->deleteJson("/api/v1/stores/{$storeB->store_name}")
            ->assertForbidden();
    }
}
