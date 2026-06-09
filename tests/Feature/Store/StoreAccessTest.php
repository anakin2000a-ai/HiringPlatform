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
    // Slug routing
    // -----------------------------------------------------------------------

    public function test_store_is_resolved_by_slug(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store = Store::factory()->for($franchise)->create(['name' => 'Downtown Branch']);
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $store->slug)
            ->assertJsonPath('data.name', 'Downtown Branch');
    }

    public function test_unknown_slug_returns_404(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/stores/slug-does-not-exist')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Franchise admin — full franchise scope
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_list_all_stores_in_their_franchise(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        Store::factory()->for($franchise)->count(3)->create();

        $otherFranchise = FranchiseAccount::factory()->create();
        Store::factory()->for($otherFranchise)->count(2)->create();

        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        $response = $this->actingAs($admin)->getJson('/api/v1/stores');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_franchise_admin_cannot_see_stores_from_another_franchise(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();

        $storeB = Store::factory()->for($franchiseB)->create();
        $admin = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchiseA->id]);

        // Direct access by slug must be forbidden
        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeB->slug}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Store-scoped user — only sees assigned stores
    // -----------------------------------------------------------------------

    public function test_store_manager_only_sees_assigned_stores(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $assignedStore = Store::factory()->for($franchise)->create();
        $otherStore = Store::factory()->for($franchise)->create();

        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $assignedStore->id]);

        $response = $this->actingAs($manager)->getJson('/api/v1/stores');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug');
        $this->assertTrue($slugs->contains($assignedStore->slug));
        $this->assertFalse($slugs->contains($otherStore->slug));
    }

    public function test_store_manager_cannot_access_unassigned_store_by_slug(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $unassignedStore = Store::factory()->for($franchise)->create();

        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$unassignedStore->slug}")
            ->assertForbidden();
    }

    public function test_user_cannot_access_store_from_another_franchise_by_slug(): void
    {
        $franchiseA = FranchiseAccount::factory()->create();
        $franchiseB = FranchiseAccount::factory()->create();

        $storeB = Store::factory()->for($franchiseB)->create();
        $assignedStoreA = Store::factory()->for($franchiseA)->create();

        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchiseA->id]);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $assignedStoreA->id]);

        $this->actingAs($manager)
            ->getJson("/api/v1/stores/{$storeB->slug}")
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
