<?php

namespace Tests\Feature\Store;

use App\Models\FranchiseAccount;
use App\Models\Store;
use App\Models\User;
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
            'name' => 'Main Street Store',
            'city' => 'Chicago',
            'country' => 'US',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Main Street Store')
            ->assertJsonPath('data.city', 'Chicago');

        $this->assertDatabaseHas('stores', [
            'name' => 'Main Street Store',
            'franchise_account_id' => $franchise->id,
        ]);
    }

    public function test_slug_is_auto_generated_from_name(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/stores', [
            'name' => 'North Side Branch',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'north-side-branch');
    }

    public function test_custom_slug_can_be_provided(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/stores', [
            'name' => 'West Store',
            'slug' => 'my-custom-slug',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'my-custom-slug');
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        Store::factory()->for($franchise)->create(['slug' => 'existing-slug']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/stores', [
            'name' => 'Another Store',
            'slug' => 'existing-slug',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['slug']]);
    }

    public function test_same_name_stores_receive_unique_slugs(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)->postJson('/api/v1/stores', ['name' => 'Corner Store'])->assertCreated();
        $response = $this->actingAs($admin)->postJson('/api/v1/stores', ['name' => 'Corner Store']);

        $response->assertCreated();
        $slug = $response->json('data.slug');
        $this->assertStringStartsWith('corner-store', $slug);
        $this->assertNotSame('corner-store', $slug);
    }

    public function test_slug_is_globally_unique_across_franchises(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();

        Store::factory()->for($franchiseA)->create(['slug' => 'shared-slug']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseB->id]);

        $response = $this->actingAs($admin)->postJson('/api/v1/stores', [
            'name' => 'Some Store',
            'slug' => 'shared-slug',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['slug']]);
    }

    public function test_store_manager_cannot_create_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($manager)->postJson('/api/v1/stores', ['name' => 'New Store'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_update_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $response = $this->actingAs($admin)->patchJson("/api/v1/stores/{$store->slug}", [
            'city' => 'Los Angeles',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.city', 'Los Angeles');
    }

    public function test_slug_update_must_be_unique(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        Store::factory()->for($franchise)->create(['slug' => 'taken-slug']);
        $store = Store::factory()->for($franchise)->create(['slug' => 'my-store']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)->patchJson("/api/v1/stores/{$store->slug}", [
            'slug' => 'taken-slug',
        ])->assertUnprocessable();
    }

    public function test_store_can_keep_its_own_slug_on_update(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['slug' => 'my-store']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)->patchJson("/api/v1/stores/{$store->slug}", [
            'slug' => 'my-store',
            'city' => 'Denver',
        ])->assertOk();
    }

    public function test_franchise_admin_cannot_update_store_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();
        $storeB = Store::factory()->for($franchiseB)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseA->id]);

        $this->actingAs($admin)->patchJson("/api/v1/stores/{$storeB->slug}", ['city' => 'NYC'])
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

        $this->actingAs($admin)->deleteJson("/api/v1/stores/{$store->slug}")->assertOk();
        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_store_manager_cannot_delete_a_store(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($manager)->deleteJson("/api/v1/stores/{$store->slug}")->assertForbidden();
    }
}
