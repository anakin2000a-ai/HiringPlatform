<?php

namespace App\Services\Events\Handlers;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserRoleStoreToggledHandler implements EventHandlerInterface
{
    /**
     * Handle auth.v1.assignment.user_role_store.toggled.
     *
     * Supports Shape A (data.assignment.{user_id, store_id, after_is_active})
     * and Shape B (data.{user_id, store_id, after_is_active}).
     *
     * The local user_store_access table has no is_active/status column, so
     * the active state is modelled by row presence:
     *   - after_is_active true  → ensure the row exists (create if missing)
     *   - after_is_active false → ensure the row is absent (delete if present)
     *
     * If the row must be created but the referenced user or store does not yet
     * exist locally, a RuntimeException is thrown so the inbox event is marked
     * failed and retried.
     *
     * If after_is_active is false and no row exists, the event is a safe no-op.
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

        // Prefer after_is_active; fall back to is_active for partial payloads.
        $afterActive = $assignment['after_is_active'] ?? $assignment['is_active'] ?? null;
        $isActive    = $afterActive !== null ? (bool) $afterActive : true;

        $userId  = (int) $userId;
        $storeId = (int) $storeId;

        DB::transaction(function () use ($userId, $storeId, $isActive): void {
            $exists = UserStoreAccess::where('user_id', $userId)
                ->where('store_id', $storeId)
                ->exists();

            if ($isActive) {
                if ($exists) {
                    return; // already active — no-op
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
                    'user_id'  => $userId,
                    'store_id' => $storeId,
                ]);
            } else {
                if (! $exists) {
                    return; // already absent — no-op
                }

                UserStoreAccess::where('user_id', $userId)
                    ->where('store_id', $storeId)
                    ->delete();
            }
        });
    }
}
