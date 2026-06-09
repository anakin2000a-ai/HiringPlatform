<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFranchiseAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isFranchiseAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isFranchiseAdmin()
            && $user->franchise_account_id === $target->franchise_account_id;
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isFranchiseAdmin()
            && $user->franchise_account_id === $target->franchise_account_id
            && $user->id !== $target->id;
    }
}
