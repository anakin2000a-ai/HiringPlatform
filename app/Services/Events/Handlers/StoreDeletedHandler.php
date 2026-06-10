<?php

namespace App\Services\Events\Handlers;

use App\Models\Store;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreDeletedHandler implements EventHandlerInterface
{
    /**
     * Handle an auth.v1.store.deleted event.
     *
     * Sets stores.status = 'inactive' rather than physically deleting the row.
     * Physical deletion is unsafe: stores are referenced by job_openings,
     * applications, workflow_stages, and other hiring data via foreign keys.
     * Marking the store inactive preserves all hiring history while reflecting
     * the auth-service deletion intent.
     *
     * If the store does not exist locally, the event is treated as a no-op.
     * No hiring data (job_openings, applications, activities) is touched.
     */
    public function handle(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        $store = Store::find($id);
        if ($store === null) {
            return;
        }

        DB::transaction(static function () use ($store): void {
            $store->update(['status' => 'inactive']);
        });
    }
}
