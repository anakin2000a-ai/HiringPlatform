<?php

namespace App\Services\Events\Handlers;

use App\Enums\UserStatus;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserRoleStoreBulkAssignedHandler implements EventHandlerInterface
{
    private const ALLOWED_ROLES = ['franchise_admin', 'store_manager', 'recruiter', 'viewer'];

    /**
     * Handle auth.v1.assignment.user_role_store.bulk_assigned.
     *
     * Expects data.assignments — an array of assignment objects, each in either:
     *   Shape A: {assignment: {user_id, store_id, role, access_scope, ...}}
     *   Shape B: {user_id, store_id, role, access_scope, ...}
     *
     * Atomic behavior: all referenced users and stores are validated before any
     * row is written.  If any user or store is missing locally, a RuntimeException
     * is thrown and the entire event is marked failed — no partial rows are written.
     *
     * role defaults to 'viewer', access_scope defaults to 'store' when absent.
     */
   public function handle(array $data): void
{
    $assignments = $data['assignments'] ?? [];
    $topLevelUserId = $data['user_id'] ?? null;

    if (empty($assignments) || ! is_array($assignments)) {
        return;
    }

    $pairs = $this->extractPairs($assignments, $topLevelUserId);

    if (empty($pairs)) {
        return;
    }

    DB::transaction(function () use ($pairs): void {
        foreach ($pairs as $pair) {
            if (! User::where('id', $pair['user_id'])->exists()) {
                throw new RuntimeException(
                    "user_id {$pair['user_id']} does not exist locally. " .
                    'Retry after auth.v1.user.created is processed.'
                );
            }

            if (! Store::where('id', $pair['store_id'])->exists()) {
                throw new RuntimeException(
                    "store_id {$pair['store_id']} does not exist locally. " .
                    'Retry after auth.v1.store.created is processed.'
                );
            }
        }

        foreach ($pairs as $pair) {
            UserStoreAccess::firstOrCreate(
                ['user_id' => $pair['user_id'], 'store_id' => $pair['store_id']],
                ['role' => $pair['role'], 'access_scope' => $pair['access_scope'], 'status' => UserStatus::Active]
            );
        }
    });
}

    /**
     * @param  array<int, mixed>  $assignments
     * @return array<int, array{user_id: int, store_id: int, role: string, access_scope: string}>
     */
   private function extractPairs(array $assignments, mixed $topLevelUserId = null): array
{
    $pairs = [];

    foreach ($assignments as $item) {
        if (! is_array($item)) {
            continue;
        }

        $a = isset($item['assignment']) && is_array($item['assignment'])
            ? $item['assignment']
            : $item;

        $userId  = $a['user_id'] ?? $topLevelUserId;
        $storeId = $a['store_id'] ?? null;

        if ($userId === null || $storeId === null) {
            continue;
        }

        $role = (isset($a['role']) && in_array($a['role'], self::ALLOWED_ROLES, true))
            ? $a['role']
            : 'viewer';

        $scope = (isset($a['access_scope']) && in_array($a['access_scope'], ['franchise', 'store'], true))
            ? $a['access_scope']
            : 'store';

        $pairs[] = [
            'user_id' => (int) $userId,
            'store_id' => $storeId,
            'role' => $role,
            'access_scope' => $scope,
        ];
    }

    return $pairs;
}
}
