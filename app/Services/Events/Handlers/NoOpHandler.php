<?php

namespace App\Services\Events\Handlers;

use App\Services\Events\EventHandlerInterface;

class NoOpHandler implements EventHandlerInterface
{
    /**
     * Accepts the event without performing any local side effects.
     *
     * Used for subjects where the hiring platform acknowledges receipt for
     * idempotency purposes but does not act on the data (e.g. auth.v1.user.created,
     * auth.v1.store.deleted).
     */
    public function handle(array $data): void
    {
        // Intentional no-op.
    }
}
