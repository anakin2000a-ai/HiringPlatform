<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishHiringOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public string $outboxEventId;

    public function __construct(string $outboxEventId)
    {
        $this->outboxEventId = $outboxEventId;
    }

    public function handle(JetStreamPublisher $publisher): void
    {
        $event = OutboxEvent::query()->where('id', $this->outboxEventId)->firstOrFail();

        if ($event->published_at) {
            return;
        }

        $event->attempts = $event->attempts + 1;
        $event->save();

        $publisher->publish($event->subject, $event->payload);

        $event->published_at = now();
        $event->last_error   = null;
        $event->save();
    }

    public function failed(\Throwable $e): void
    {
        OutboxEvent::query()->where('id', $this->outboxEventId)->update(['last_error' => $e->getMessage()]);
    }
}
