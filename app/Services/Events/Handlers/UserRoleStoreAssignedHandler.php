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
    /**
     * Handle auth.v1.assignment.user_role_store.assigned.
     *
     * Supports two payload shapes:
     *   Shape A — data.assignment.{user_id, store_id, role_id, is_active, metadata}
     *   Shape B — data.{user_id, store_id, role_id, is_active, metadata}
     *
     * The local user_store_access table has only (user_id, store_id); role_id,
     * metadata, and is_active have no columns and are silently ignored.
     *
     * Fails with RuntimeException (retryable) if the referenced user or store
     * does not yet exist locally — the event will be retried after the
     * user.created / store.created event arrives.
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

        DB::transaction(static function () use ($userId, $storeId): void {
            UserStoreAccess::firstOrCreate([
                'user_id'  => (int) $userId,
                'store_id' => (int) $storeId,
            ]);
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
