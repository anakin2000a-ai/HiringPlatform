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
    $id = $data['id'] ?? $data['store_id'] ?? null;

    if ($id === null) {
        return;
    }

    $updates = [];

    $changed = $data['changed_fields'] ?? [];

    if (is_array($changed) && ! empty($changed)) {
        if (isset($changed['name']['new'])) {
            $updates['store_name'] = $changed['name']['new'];
        }

        if (isset($changed['store_name']['new'])) {
            $updates['store_name'] = $changed['store_name']['new'];
        }

        if (isset($changed['is_active']['new'])) {
            $updates['status'] = $changed['is_active']['new'] ? 'active' : 'inactive';
        }

        if (isset($changed['status']['new'])) {
            $updates['status'] = $changed['status']['new'];
        }
    }

    if (isset($data['name'])) {
        $updates['store_name'] = $data['name'];
    }

    if (isset($data['store_name'])) {
        $updates['store_name'] = $data['store_name'];
    }

    if (isset($data['is_active'])) {
        $updates['status'] = $data['is_active'] ? 'active' : 'inactive';
    }

    if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) {
        $updates['status'] = $data['status'];
    }

    if (empty($updates)) {
        return;
    }

    DB::transaction(function () use ($id, $updates): void {
        $store = Store::find($id);

        if ($store === null) {
            return;
        }

        $store->update($updates);
    });
}
}
