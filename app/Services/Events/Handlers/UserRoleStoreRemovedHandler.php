<?php

namespace App\Services\Events\Handlers;

use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserRoleStoreRemovedHandler implements EventHandlerInterface
{
    /**
     * Handle auth.v1.assignment.user_role_store.removed.
     *
     * Supports Shape A (data.assignment.{user_id, store_id}) and
     * Shape B (data.{user_id, store_id}).
     *
     * The local user_store_access table has no is_active/status column, so
     * removal is represented by deleting the row.  If no row exists, this
     * event is a safe no-op.  The referenced user and store rows are never
     * touched.
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

        DB::transaction(static function () use ($userId, $storeId): void {
            UserStoreAccess::where('user_id', (int) $userId)
                ->where('store_id', (int) $storeId)
                ->delete();
        });
    }
}
