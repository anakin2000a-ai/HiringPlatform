<?php

namespace App\Services\Events\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserDeletedHandler implements EventHandlerInterface
{
    /**
     * Handle an auth.v1.user.deleted event.
     *
     * The users table has no SoftDeletes trait, but it does have a `status`
     * column (default 'active').  Rather than physically deleting the row —
     * which would cascade-null hiring records linked to this user — the user
     * is marked inactive so they cannot log in but all data references remain
     * intact.
     *
     * If the user does not exist locally, the event is treated as a no-op.
     * Business data (applications, stage transitions, activities) is never touched.
     */
    public function handle(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        $user = User::find($id);
        if ($user === null) {
            return;
        }

        DB::transaction(static function () use ($user): void {
            $user->delete();
        });
    }
}
