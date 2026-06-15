<?php

namespace App\Services\Events\Handlers;

use App\Models\Store;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreDeletedHandler implements EventHandlerInterface
{
    public function handle(array $data): void
    {
        $id = $data['id'] ?? $data['store_id'] ?? null;

        if ($id === null) {
            return;
        }

        $store = Store::find($id);

        if ($store === null) {
            return;
        }

        DB::transaction(static function () use ($store): void {
          
            $store->delete();
        });
    }
}