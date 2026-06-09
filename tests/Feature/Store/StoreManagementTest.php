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
    // Create
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_create_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

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
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)->postJson('/api/v1/stores', ['store_name' => 'New Store']);

        $this->assertDatabaseHas('stores', ['franchise_account_id' => $franchise->id]);
    }

    public function test_store_manager_cannot_create_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($manager)
            ->postJson('/api/v1/stores', ['store_name' => 'New Store'])
            ->assertForbidden();
    }

    public function test_create_store_requires_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/stores', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['store_name']]);
    }

    // -----------------------------------------------------------------------
    // Show
    // -----------------------------------------------------------------------

    public function test_show_returns_store_by_id(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['store_name' => 'West Branch']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.store_name', 'West Branch');
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_update_store_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['store_name' => 'Old Name']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}", ['store_name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.store_name', 'New Name');

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'store_name' => 'New Name']);
    }

    public function test_store_manager_assigned_to_store_cannot_update_it(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id]);

        // Middleware allows access; role check in controller blocks modification
        $this->actingAs($manager)
            ->patchJson("/api/v1/stores/{$store->id}", ['store_name' => 'Hacked Name'])
            ->assertForbidden();
    }

    public function test_franchise_admin_cannot_update_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $adminA = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseA->id]);

        // Middleware blocks because admin cannot access franchiseB's store
        $this->actingAs($adminA)
            ->patchJson("/api/v1/stores/{$storeB->id}", ['store_name' => 'Hacked'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_delete_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}")
            ->assertOk();

        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_store_manager_assigned_to_store_cannot_delete_it(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id]);

        $this->actingAs($manager)
            ->deleteJson("/api/v1/stores/{$store->id}")
            ->assertForbidden();
    }

    public function test_franchise_admin_cannot_delete_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $adminA = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseA->id]);

        $this->actingAs($adminA)
            ->deleteJson("/api/v1/stores/{$storeB->id}")
            ->assertForbidden();
    }
}
