<?php

namespace App\Console\Commands;

use App\Services\Nats\JetStreamConsumer;
use Illuminate\Console\Command;

class ConsumeNatsEventsCommand extends Command
{
    protected $signature = 'events:consume-nats
                            {--once : Pull one batch per stream then exit (useful for cron/testing)}
                            {--batch=10 : Maximum messages to pull per stream per run}';

    protected $description = 'Pull and process inbound events from NATS JetStream';

    public function __construct(private readonly JetStreamConsumer $consumer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $streams = config('nats.streams', []);

        if (empty($streams)) {
            $this->error('No NATS streams configured. Check config/nats.php.');
            return Command::FAILURE;
        }

        try {
            if ($this->option('once')) {
                $batch = (int) $this->option('batch');
                $total = 0;

                foreach ($streams as $streamConfig) {
                    $total += $this->consumer->consume($streamConfig, $batch);
                }

                $this->line("Processed: {$total} events");
                return Command::SUCCESS;
            }

            // Long-running mode — never returns unless killed
            $this->consumer->runForever();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
