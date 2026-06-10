<?php

namespace App\Console\Commands;

use App\Services\Events\OutboxPublisherService;
use Illuminate\Console\Command;

class PublishOutboxEventsCommand extends Command
{
    protected $signature = 'events:publish-outbox
                            {--limit=50 : Maximum number of events to process per run}
                            {--max-attempts=5 : Maximum publish attempts before marking an event failed}';

    protected $description = 'Publish pending outbox events to the event bus';

    public function __construct(private readonly OutboxPublisherService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit       = (int) $this->option('limit');
        $maxAttempts = (int) $this->option('max-attempts');

        $result = $this->service->run($limit, $maxAttempts);

        $this->line("Published: {$result['published']}");
        $this->line("Failed:    {$result['failed']}");
        $this->line("Retried:   {$result['retried']}");

        return Command::SUCCESS;
    }
}
