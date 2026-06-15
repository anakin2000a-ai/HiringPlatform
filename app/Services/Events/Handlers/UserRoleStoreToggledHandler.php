<?php

namespace App\Services\Events\Handlers;

use App\Enums\UserStatus;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserRoleStoreToggledHandler implements EventHandlerInterface
{
    private const ALLOWED_ROLES = ['franchise_admin', 'store_manager', 'recruiter', 'viewer'];

    /**
     * Handle auth.v1.assignment.user_role_store.toggled.
     *
     * Supports Shape A (data.assignment.{user_id, store_id, after_is_active, role, access_scope})
     * and Shape B (data.{user_id, store_id, after_is_active, role, access_scope}).
     *
     * Active state is modelled by row presence:
     *   - after_is_active true  → ensure the row exists (create if missing)
     *   - after_is_active false → ensure the row is absent (delete if present)
     *
     * role defaults to 'viewer', access_scope defaults to 'store' when absent.
     */
    public function handle(array $data): void
    {
        $assignment = isset($data['assignment']) && is_array($data['assignment'])
            ? $data['assignment']
            : $data;

        $userId  = $assignment['user_id']  ?? null;
        $storeId = $assignment['store_id'] ?? null;

        if ($userId === null || $storeId === null) {
            return;
        }

        $afterActive = $assignment['after_is_active'] ?? $assignment['is_active'] ?? null;
        $isActive    = $afterActive !== null ? (bool) $afterActive : true;

        $userId  = (int) $userId;
        $storeId = (int) $storeId;

        $role  = (isset($assignment['role']) && in_array($assignment['role'], self::ALLOWED_ROLES, true))
            ? $assignment['role']
            : 'viewer';
        $scope = (isset($assignment['access_scope']) && in_array($assignment['access_scope'], ['franchise', 'store'], true))
            ? $assignment['access_scope']
            : 'store';

        DB::transaction(function () use ($userId, $storeId, $isActive, $role, $scope): void {
            $exists = UserStoreAccess::where('user_id', $userId)
                ->where('store_id', $storeId)
                ->exists();

            if ($isActive) {
                if ($exists) {
                    return;
                }

                if (! User::where('id', $userId)->exists()) {
                    throw new RuntimeException(
                        "user_id {$userId} does not exist locally. " .
                        'Retry after auth.v1.user.created is processed.'
                    );
                }

                if (! Store::where('id', $storeId)->exists()) {
                    throw new RuntimeException(
                        "store_id {$storeId} does not exist locally. " .
                        'Retry after auth.v1.store.created is processed.'
                    );
                }

                UserStoreAccess::create([
                    'user_id'      => $userId,
                    'store_id'     => $storeId,
                    'role'         => $role,
                    'access_scope' => $scope,
                    'status'       => UserStatus::Active,
                ]);
            } else {
                if (! $exists) {
                    return;
                }

                UserStoreAccess::where('user_id', $userId)
                    ->where('store_id', $storeId)
                    ->delete();
            }
        });
    }
}
