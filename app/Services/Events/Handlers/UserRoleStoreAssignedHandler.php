<?php

namespace App\Services\Events\Handlers;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserRoleStoreAssignedHandler implements EventHandlerInterface
{
    private const ALLOWED_ROLES = ['franchise_admin', 'store_manager', 'recruiter', 'viewer'];

    /**
     * Handle auth.v1.assignment.user_role_store.assigned.
     *
     * Supports two payload shapes:
     *   Shape A — data.assignment.{user_id, store_id, role, access_scope, ...}
     *   Shape B — data.{user_id, store_id, role, access_scope, ...}
     *
     * role defaults to 'viewer', access_scope defaults to 'store' when absent.
     * status is always set to 'active' on creation.
     *
     * Fails with RuntimeException (retryable) if the referenced user or store
     * does not yet exist locally.
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

        $this->assertUserExists((int) $userId);
        $this->assertStoreExists((int) $storeId);

        $role  = (isset($assignment['role']) && in_array($assignment['role'], self::ALLOWED_ROLES, true))
            ? $assignment['role']
            : 'viewer';
        $scope = (isset($assignment['access_scope']) && in_array($assignment['access_scope'], ['franchise', 'store'], true))
            ? $assignment['access_scope']
            : 'store';

        DB::transaction(static function () use ($userId, $storeId, $role, $scope): void {
            UserStoreAccess::firstOrCreate(
                ['user_id' => (int) $userId, 'store_id' => (int) $storeId],
                ['role' => $role, 'access_scope' => $scope, 'status' => 'active']
            );
        });
    }

    private function assertUserExists(int $userId): void
    {
        if (! User::where('id', $userId)->exists()) {
            throw new RuntimeException(
                "user_id {$userId} does not exist locally. " .
                'Retry after auth.v1.user.created is processed.'
            );
        }
    }

    private function assertStoreExists(int $storeId): void
    {
        if (! Store::where('id', $storeId)->exists()) {
            throw new RuntimeException(
                "store_id {$storeId} does not exist locally. " .
                'Retry after auth.v1.store.created is processed.'
            );
        }
    }
}
