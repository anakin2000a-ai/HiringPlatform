<?php

namespace App\Services\Events\Handlers;

use App\Models\User;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserUpdatedHandler implements EventHandlerInterface
{
    private const ALLOWED_ROLES = ['franchise_admin', 'store_manager', 'recruiter', 'viewer'];

    /**
     * Handle an auth.v1.user.updated event.
     *
     * - If the local user exists, update only allowlisted fields (name, email, role).
     * - If the user does not exist and name + email are present, create the user
     *   (the auth service is authoritative; we should not silently discard an update
     *   for a user that was never received via user.created).
     * - If the user does not exist and minimum required fields are missing, returns
     *   silently (safe no-op; there is nothing valid to persist).
     * - role is only applied if it is present in the payload and in ALLOWED_ROLES.
     * - password is never updated from this event.
     * - user_store_access rows are NOT created.
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

            $role = (isset($data['role']) && in_array($data['role'], self::ALLOWED_ROLES, true))
                ? $data['role']
                : 'viewer';

            DB::transaction(function () use ($id, $name, $email, $role): void {
                User::forceCreate([
                    'id'       => $id,
                    'name'     => $name,
                    'email'    => $email,
                    'password' => bcrypt(Str::random(32)),
                    'role'     => $role,
                ]);
            });

            return;
        }

        $updates = array_intersect_key($data, array_flip(['name', 'email']));

        if (isset($data['role']) && in_array($data['role'], self::ALLOWED_ROLES, true)) {
            $updates['role'] = $data['role'];
        }

        if (empty($updates)) {
            return;
        }

        DB::transaction(static function () use ($user, $updates): void {
            $user->update($updates);
        });
    }
}
