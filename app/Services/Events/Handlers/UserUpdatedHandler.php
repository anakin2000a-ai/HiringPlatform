<?php

namespace App\Services\Events\Handlers;

use App\Models\User;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserUpdatedHandler implements EventHandlerInterface
{
    /**
     * Handle an auth.v1.user.updated event.
     *
     * - If the local user exists, update only name and email.
     * - If the user does not exist and name + email are present, create the user
     *   (the auth service is authoritative; we should not silently discard an update
     *   for a user that was never received via user.created).
     * - If the user does not exist and minimum required fields are missing, returns
     *   silently (safe no-op; there is nothing valid to persist).
     * - role/access_scope are NOT updated here; they belong to user_store_access rows.
     * - password is never updated from this event.
     */
    public function handle(array $data): void
    {
        $id = $data['id'] ?? null;
        if ($id === null) {
            return;
        }

        $user = User::find($id);

        if ($user === null) {
            $name  = $data['name'] ?? null;
            $email = $data['email'] ?? null;

            if ($name === null || $email === null) {
                return;
            }

            DB::transaction(function () use ($id, $name, $email): void {
                User::forceCreate([
                    'id'       => $id,
                    'name'     => $name,
                    'email'    => $email,
                    'password' => bcrypt(Str::random(32)),
                ]);
            });

            return;
        }

        $updates = array_intersect_key($data, array_flip(['name', 'email']));

        if (empty($updates)) {
            return;
        }

        DB::transaction(static function () use ($user, $updates): void {
            $user->update($updates);
        });
    }
}
