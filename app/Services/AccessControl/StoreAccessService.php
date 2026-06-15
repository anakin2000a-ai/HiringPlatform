<?php

namespace App\Services\AccessControl;

use App\Enums\UserStatus;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Database\Eloquent\Builder;

class StoreAccessService
{
    public function canAccessStore(User $user, Store $store): bool
    {
        return UserStoreAccess::query()
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('status', UserStatus::Active)
            ->exists();
    }

    public function accessibleStoreIds(User $user): array
    {
        return UserStoreAccess::query()
            ->where('user_id', $user->id)
            ->where('status', UserStatus::Active)
            ->pluck('store_id')
            ->toArray();
    }

    public function getUserRoleAtStore(User $user, Store $store): ?string
    {
        return UserStoreAccess::query()
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->where('status', UserStatus::Active)
            ->value('role');
    }

    public function getFranchiseAccountIdForAdmin(User $user): ?int
    {
        $storeId = UserStoreAccess::query()
            ->where('user_id', $user->id)
            ->where('status', UserStatus::Active)
            ->value('store_id');

        if ($storeId === null) {
            return null;
        }

        return Store::where('id', $storeId)->value('franchise_account_id');
    }

    public function scopeQueryToAccessibleStores(
        Builder $query,
        User $user,
        string $storeColumn = 'store_id'
    ): Builder {
        return $query->whereIn($storeColumn, $this->accessibleStoreIds($user));
    }
}