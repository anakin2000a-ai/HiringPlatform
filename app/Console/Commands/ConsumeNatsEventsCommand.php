<?php

namespace App\Console\Commands;

use App\Services\Nats\JetStreamConsumer;
use Illuminate\Console\Command;

class ConsumeNatsEventsCommand extends Command
{
    protected $signature =  'app:nats-consume';

    protected $description = 'Pull and process inbound events from NATS JetStream';

    public function handle(JetStreamConsumer $consumer): int
    {
        $this->info('Starting JetStream consumer...');
        $consumer->runForever();

        return Command::SUCCESS;
    }
}
