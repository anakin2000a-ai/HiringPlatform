<?php

namespace App\Services\AccessControl;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Database\Eloquent\Builder;

class StoreAccessService
{
    /**
     * Check if a user is allowed to access a specific store.
     *
     * Access is granted when:
     *  - The user has a direct (active) user_store_access row for the store, OR
     *  - The user has a franchise-scope (active) user_store_access row on any store
     *    in the same franchise as the requested store.
     */
    public function canAccessStore(User $user, Store $store): bool
    {
        if ($this->hasDirectAccess($user, $store)) {
            return true;
        }

        return $this->hasFranchiseAccess($user, $store->franchise_account_id);
    }

    /**
     * Return the IDs of all stores the user can access.
     */
    public function accessibleStoreIds(User $user): array
    {
        $directIds = UserStoreAccess::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('store_id')
            ->toArray();

        $franchiseIds = UserStoreAccess::where('user_id', $user->id)
            ->where('access_scope', 'franchise')
            ->where('status', 'active')
            ->with('store:id,franchise_account_id')
            ->get()
            ->pluck('store.franchise_account_id')
            ->filter()
            ->unique()
            ->toArray();

        if (empty($franchiseIds)) {
            return $directIds;
        }

        $franchiseStoreIds = Store::whereIn('franchise_account_id', $franchiseIds)
            ->pluck('id')
            ->toArray();

        return array_values(array_unique(array_merge($directIds, $franchiseStoreIds)));
    }

    /**
     * Get the user's role at a specific store.
     *
     * Checks direct store access first, then franchise-scope access.
     * Returns null if the user has no access to that store.
     */
    public function getUserRoleAtStore(User $user, Store $store): ?string
    {
        $direct = UserStoreAccess::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->value('role');

        if ($direct !== null) {
            return $direct;
        }

        return UserStoreAccess::where('user_id', $user->id)
            ->where('access_scope', 'franchise')
            ->where('status', 'active')
            ->whereHas('store', fn ($q) => $q->where('franchise_account_id', $store->franchise_account_id))
            ->value('role');
    }

    /**
     * Get the franchise_account_id that the user administers with franchise scope.
     *
     * Used for operations that create resources scoped to a franchise (e.g. store creation)
     * where no specific store is yet available in the request context.
     *
     * Returns null if the user has no franchise-admin / franchise-scope access.
     */
    public function getFranchiseAccountIdForAdmin(User $user): ?int
    {
        $access = UserStoreAccess::where('user_id', $user->id)
            ->where('role', 'franchise_admin')
            ->where('access_scope', 'franchise')
            ->where('status', 'active')
            ->with('store:id,franchise_account_id')
            ->first();

        return $access?->store?->franchise_account_id;
    }

    /**
     * Scope an Eloquent query to only include rows the user can access.
     *
     * @param  string  $storeColumn  Column name that holds the store_id (default: 'store_id').
     *                               Use 'id' when querying the stores table itself.
     */
    public function scopeQueryToAccessibleStores(Builder $query, User $user, string $storeColumn = 'store_id'): Builder
    {
        return $query->whereIn($storeColumn, $this->accessibleStoreIds($user));
    }

    private function hasDirectAccess(User $user, Store $store): bool
    {
        return UserStoreAccess::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->exists();
    }

    private function hasFranchiseAccess(User $user, int $franchiseAccountId): bool
    {
        return UserStoreAccess::where('user_id', $user->id)
            ->where('access_scope', 'franchise')
            ->where('status', 'active')
            ->whereHas('store', fn ($q) => $q->where('franchise_account_id', $franchiseAccountId))
            ->exists();
    }
}
