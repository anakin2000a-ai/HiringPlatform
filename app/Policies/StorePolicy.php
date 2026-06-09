<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Services\AccessControl\StoreAccessService;

class StorePolicy
{
    public function __construct(private readonly StoreAccessService $storeAccessService) {}

    public function viewAny(User $user): bool
    {
        return true; // result set is filtered by StoreAccessService
    }

    public function view(User $user, Store $store): bool
    {
        return $this->storeAccessService->canAccessStore($user, $store);
    }

    public function create(User $user): bool
    {
        return $user->isFranchiseAdmin();
    }

    public function update(User $user, Store $store): bool
    {
        return $user->isFranchiseAdmin()
            && $user->franchise_account_id === $store->franchise_account_id;
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->isFranchiseAdmin()
            && $user->franchise_account_id === $store->franchise_account_id;
    }
}
