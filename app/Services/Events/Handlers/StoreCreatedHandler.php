<?php

namespace App\Services\Events\Handlers;

use App\Enums\StoreStatus;
use App\Models\FranchiseAccount;
use App\Models\Store;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreCreatedHandler implements EventHandlerInterface
{
    /**
     * Synchronize an auth.v1.store.created event into the local stores table.
     *
     * - Requires data.franchise_account_id to be present and locally resolvable.
     *   If it is missing or does not match a local franchise_accounts row, a
     *   RuntimeException is thrown so InboxEventProcessor marks the inbox event
     *   as failed with last_error — the event can be retried once the franchise
     *   is synchronized.
     * - Matches by data.id.  If the store already exists, store_name is updated
     *   (idempotent re-create).
     * - If the store does not exist, it is inserted via forceCreate so that the
     *   external id is used as the local primary key.
     * - franchise_accounts rows are never created from this event.
     */
public function handle(array $data): void
{
    $storePayload = isset($data['store']) && is_array($data['store'])
        ? $data['store']
        : $data;

    $id = $storePayload['id'] ?? $data['store_id'] ?? null;

    $storeName = $storePayload['store_name']
        ?? $storePayload['name']
        ?? $data['store_name']
        ?? $data['name']
        ?? null;

    if ($id === null || $storeName === null) {
        return;
    }

    DB::transaction(function () use ($id, $storeName): void {
        $store = Store::find($id);

        if ($store !== null) {
            $store->update([
                'store_name' => $storeName,
                'status' => StoreStatus::Active,
            ]);

            return;
        }

        Store::forceCreate([
            'id' => $id,
            'store_name' => $storeName,
            'status' => StoreStatus::Active,
        ]);
    });
}
}
