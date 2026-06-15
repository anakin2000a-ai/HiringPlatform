<?php

namespace App\Services\Events;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

class OutboxPublisherService
{
    /**
     * Exponential back-off delays in minutes, indexed by (attempts - 1).
     * Attempt 1 → 1 min, attempt 2 → 5 min, attempt 3 → 15 min, attempt 4+ → 60 min.
     */
    private const BACKOFF_MINUTES = [1, 5, 15, 60];

    public function __construct(private readonly EventBusPublisher $publisher) {}

    /**
     * Process a batch of pending outbox events.
     *
     * Each event is claimed with a per-row SELECT...FOR UPDATE lock inside its own
     * transaction so concurrent workers cannot double-publish the same event.
     *
     * @return array{published: int, failed: int, retried: int}
     */
    public function run(int $limit = 50, int $maxAttempts = 5): array
    {
        $published = 0;
        $failed    = 0;
        $retried   = 0;

        // Collect candidate IDs first (no lock yet) so we can process each in
        // its own short transaction, keeping lock duration minimal.
        $candidateIds = OutboxEvent::where('status', OutboxEventStatus::Pending)
            ->where(function ($q): void {
                $q->whereNull('available_at')
                  ->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidateIds as $id) {
            $outcome = DB::transaction(function () use ($id, $maxAttempts): string {
                // Re-fetch with a row lock; skip if another worker already claimed it.
                $event = OutboxEvent::where('id', $id)
                    ->where('status', OutboxEventStatus::Pending)
                    ->lockForUpdate()
                    ->first();

                if ($event === null) {
                    return 'skipped';
                }

                try {
                    $this->publisher->publish($event->subject, $event->payload);

                    $event->update([
                        'status'       => OutboxEventStatus::Published,
                        'published_at' => now(),
                        'failed_at'    => null,
                        'last_error'   => null,
                    ]);

                    return 'published';
                } catch (\Throwable $e) {
                    $attempts = $event->attempts + 1;

                    $updates = [
                        'attempts'   => $attempts,
                        'last_error' => $e->getMessage(),
                    ];

                    if ($attempts >= $maxAttempts) {
                        $updates['status']    = OutboxEventStatus::Failed;
                        $updates['failed_at'] = now();
                        $event->update($updates);

                        return 'failed';
                    }

                    $backoffIndex            = min($attempts - 1, count(self::BACKOFF_MINUTES) - 1);
                    $updates['available_at'] = now()->addMinutes(self::BACKOFF_MINUTES[$backoffIndex]);
                    $event->update($updates);

                    return 'retried';
                }
            });

            match ($outcome) {
                'published' => $published++,
                'failed'    => $failed++,
                'retried'   => $retried++,
                default     => null,
            };
        }

        return compact('published', 'failed', 'retried');
    }
}
