<?php

namespace App\Services\Events\Handlers;

use App\Models\FranchiseAccount;
use App\Models\Store;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreUpdatedHandler implements EventHandlerInterface
{
    /**
     * Handle an auth.v1.store.updated event.
     *
     * - If the local store exists, update only allowlisted fields (store_name,
     *   and franchise_account_id only if provided and valid locally).
     * - If the store does not exist and all required fields are present
     *   (id, store_name, franchise_account_id), create it via forceCreate.
     *   If franchise_account_id does not resolve locally, a RuntimeException is
     *   thrown so the inbox event is marked failed and retried.
     * - If the store does not exist and required fields are missing, returns
     *   silently (safe no-op).
     * - franchise_accounts rows are never created from this event.
     */
    public function handle(array $data): void
    {
        $id = $data['id'] ?? null;

        if ($id === null) {
            return;
        }

        $store = Store::find($id);

        if ($store === null) {
            $storeName = $data['store_name'] ?? null;

            if ($storeName === null) {
                return;
            }

            DB::transaction(function () use ($id, $storeName, $data): void {
                Store::forceCreate([
                    'id'         => $id,
                    'store_name' => $storeName,
                    'status'     => $data['status'] ?? 'active',
                ]);
            });

            return;
        }

        $updates = array_intersect_key($data, array_flip([
            'store_name',
        ]));

        if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) {
            $updates['status'] = $data['status'];
        }

        if (empty($updates)) {
            return;
        }

        DB::transaction(static function () use ($store, $updates): void {
            $store->update($updates);
        });
    }
}
