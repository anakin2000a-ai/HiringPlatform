<?php

namespace App\Services\Events\Handlers;

use App\Enums\UserStatus;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Support\Facades\Log;
class UserRoleStoreToggledHandler implements EventHandlerInterface
{
    /**
     * Handle auth.v1.assignment.user_role_store.toggled.
     *
     * Uses only user_id and store_id.
     *
     * Local toggle behavior:
     * - if row exists     => delete it
     * - if row not exists => create it
     */
  
   public function handle(array $data): void
    {
        Log::info('UserRoleStoreToggledHandler received event', [
            'payload' => $data,
        ]);

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $assignment = isset($data['assignment']) && is_array($data['assignment'])
            ? $data['assignment']
            : $data;

        $userId  = $assignment['user_id'] ?? null;
        $storeId = $assignment['store_id'] ?? null;

        if ($userId === null || $storeId === null) {
            Log::warning('UserRoleStoreToggledHandler missing user_id or store_id', [
                'assignment' => $assignment,
            ]);

            return;
        }

        $userId  = (int) $userId;
        $storeId = (int) $storeId;

        $afterActive = $assignment['after_is_active'] ?? null;

        if ($afterActive === null) {
            Log::warning('UserRoleStoreToggledHandler missing after_is_active', [
                'assignment' => $assignment,
            ]);

            return;
        }

        $newStatus = (bool) $afterActive
            ? UserStatus::Active
            : UserStatus::Inactive;

        DB::transaction(function () use ($userId, $storeId, $newStatus): void {
            $access = UserStoreAccess::where('user_id', $userId)
                ->where('store_id', $storeId)
                ->first();

            if ($access) {
                $access->update([
                    'status' => $newStatus,
                ]);

                Log::info('UserStoreAccess status updated', [
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'status' => $newStatus,
                ]);

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
                'role'         => 'viewer',
                'access_scope' => 'store',
                'status'       => $newStatus,
            ]);

            Log::info('UserStoreAccess created from toggled event', [
                'user_id' => $userId,
                'store_id' => $storeId,
                'status' => $newStatus,
            ]);
        });
    }
}