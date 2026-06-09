<?php

namespace App\Services\AccessControl;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StoreAccessService
{
    /**
     * Check if a user is allowed to access a specific store.
     */
    public function canAccessStore(User $user, Store $store): bool
    {
        if ($user->hasFranchiseScope()) {
            return $user->franchise_account_id === $store->franchise_account_id;
        }

        return $user->storeAccesses()->where('store_id', $store->id)->exists();
    }

    /**
     * Return the IDs of all stores the user can access.
     */
    public function accessibleStoreIds(User $user): array
    {
        if ($user->hasFranchiseScope()) {
            return Store::where('franchise_account_id', $user->franchise_account_id)
                ->pluck('id')
                ->toArray();
        }

        return $user->storeAccesses()->pluck('store_id')->toArray();
    }

    /**
     * Scope an Eloquent query to only include rows the user can access.
     *
     * @param  string  $storeColumn  Column name that holds the store_id (default: 'store_id').
     *                               Use 'id' when querying the stores table itself.
     */
    public function scopeQueryToAccessibleStores(Builder $query, User $user, string $storeColumn = 'store_id'): Builder
    {
        $ids = $this->accessibleStoreIds($user);

        return $query->whereIn($storeColumn, $ids);
    }
}
