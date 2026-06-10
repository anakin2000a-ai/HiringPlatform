<?php

namespace App\Services\Events\Handlers;

use App\Models\User;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserCreatedHandler implements EventHandlerInterface
{
    /**
     * Roles that may be assigned to externally-owned users.
     * Any role arriving in the payload that is not in this list is ignored.
     */
    private const ALLOWED_ROLES = ['franchise_admin', 'store_manager', 'recruiter', 'viewer'];

    /**
     * Synchronize an auth.v1.user.created event into the local users table.
     *
     * - Matches by data.id using the shared primary key.
     * - If a local user with that id already exists, name/email/role are updated
     *   (idempotent re-create).
     * - If the user does not exist and name + email are present, the user is
     *   created via forceCreate (bypasses fillable guard so id is respected).
     * - password is set to an unusable random hash because this user is owned by
     *   the auth service; Sanctum token-based auth is unaffected.
     * - user_store_access rows are NOT created; access management is out of scope
     *   for this inbound event.
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

                if (isset($data['role']) && in_array($data['role'], self::ALLOWED_ROLES, true)) {
                    $updates['role'] = $data['role'];
                }

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

            $role = (isset($data['role']) && in_array($data['role'], self::ALLOWED_ROLES, true))
                ? $data['role']
                : 'viewer';

            User::forceCreate([
                'id'       => $id,
                'name'     => $name,
                'email'    => $email,
                'password' => bcrypt(Str::random(32)),
                'role'     => $role,
            ]);
        });
    }
}
