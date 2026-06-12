<?php

namespace App\Services\Events\Handlers;

use App\Models\User;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserCreatedHandler implements EventHandlerInterface
{
    /**
     * Synchronize an auth.v1.user.created event into the local users table.
     *
     * - Matches by data.id using the shared primary key.
     * - If a local user with that id already exists, name/email are updated
     *   (idempotent re-create).
     * - If the user does not exist and name + email are present, the user is
     *   created via forceCreate (bypasses fillable guard so id is respected).
     * - password is set to an unusable random hash because this user is owned by
     *   the auth service; Sanctum token-based auth is unaffected.
     * - role/access_scope are NOT set here; they belong to user_store_access rows
     *   created by assignment events.
     */
    public function handle(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        DB::transaction(function () use ($id, $data): void {
            $user = User::find($id);

            if ($user !== null) {
                $updates = array_intersect_key($data, array_flip(['name', 'email']));

                if (! empty($updates)) {
                    $user->update($updates);
                }

                return;
            }

            $name  = $data['name'] ?? null;
            $email = $data['email'] ?? null;

            if ($name === null || $email === null) {
                return;
            }

            User::forceCreate([
                'id'       => $id,
                'name'     => $name,
                'email'    => $email,
                'password' => bcrypt(Str::random(32)),
            ]);
        });
    }
}
