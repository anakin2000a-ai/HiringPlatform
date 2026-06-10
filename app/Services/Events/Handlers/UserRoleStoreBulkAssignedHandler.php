<?php

namespace App\Services\Events\Handlers;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserRoleStoreBulkAssignedHandler implements EventHandlerInterface
{
    /**
     * Handle auth.v1.assignment.user_role_store.bulk_assigned.
     *
     * Expects data.assignments — an array of assignment objects, each in either:
     *   Shape A: {assignment: {user_id, store_id, ...}}
     *   Shape B: {user_id, store_id, ...}
     *
     * Atomic behavior: all referenced users and stores are validated before any
     * row is written.  If any user or store is missing locally, a RuntimeException
     * is thrown and the entire event is marked failed — no partial rows are written.
     * The event will be retried once the missing records have been synchronized.
     */
    public function handle(array $data): void
    {
        $assignments = $data['assignments'] ?? [];

        if (empty($assignments) || ! is_array($assignments)) {
            return;
        }

        $pairs = $this->extractPairs($assignments);

        if (empty($pairs)) {
            return;
        }

        DB::transaction(function () use ($pairs): void {
            // Validate all references atomically before any write.
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

            // All references valid — write all rows.
            foreach ($pairs as $pair) {
                UserStoreAccess::firstOrCreate([
                    'user_id'  => $pair['user_id'],
                    'store_id' => $pair['store_id'],
                ]);
            }
        });
    }

    /**
     * Extract (user_id, store_id) pairs from the raw assignments array,
     * normalising both Shape A and Shape B per element.
     *
     * @param  array<int, mixed>  $assignments
     * @return array<int, array{user_id: int, store_id: int}>
     */
    private function extractPairs(array $assignments): array
    {
        $pairs = [];

        foreach ($assignments as $item) {
            if (! is_array($item)) {
                continue;
            }

            $a = isset($item['assignment']) && is_array($item['assignment'])
                ? $item['assignment']
                : $item;

            $userId  = $a['user_id']  ?? null;
            $storeId = $a['store_id'] ?? null;

            if ($userId === null || $storeId === null) {
                continue;
            }

            $pairs[] = [
                'user_id'  => (int) $userId,
                'store_id' => (int) $storeId,
            ];
        }

        return $pairs;
    }
}
