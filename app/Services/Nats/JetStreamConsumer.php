<?php

namespace App\Services\Nats;

use App\Enums\InboxEventStatus;
use App\Models\InboxEvent;
use App\Services\Events\EventHandlerInterface;
use App\Services\Events\EventRouter;
use Basis\Nats\Client;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JetStreamConsumer
{
    /**
     * After this many failures for the same event_id, we ACK/TERM it and park it.
     * This guarantees "stop trying" for that event_id on the application side.
     */
    private const MAX_PROCESSING_ATTEMPTS = 5;

    /**
     * Prevent hot spinning when the consumer loop itself errors (NATS down, auth, etc).
     */
    private const ERROR_BACKOFF_MS = 1000;

    /**
     * Delay for retries when handler fails (prevents tight redelivery loop).
     * NOTE: This delay works only if the library supports nack($delaySeconds).
     */
    private const NACK_DELAY_SECONDS = 2;

    /**
     * Domain subjects prefix allowlist.
     * We ignore anything else (including the internal "handler.*" junk you saw).
     */
    private const SUBJECT_ALLOW_PREFIXES = [
        'auth.v1.',
    ];

    private ?Client $client = null;

    /**
     * Cache consumer objects per stream+durable so we don't "create called" every loop.
     * @var array<string, mixed>
     */
    private array $consumerCache = [];

    public function __construct(
        private readonly NatsClientFactory $factory,
        private readonly EventRouter $router,
    ) {}

    public function runForever(): void
    {
        $streams = (array) config('nats.streams', []);
        if (count($streams) === 0) {
            throw new Exception('No streams configured in nats.streams');
        }

        $batch     = (int) config('nats.pull.batch', 25);
        $timeoutMs = (int) config('nats.pull.timeout_ms', 2000);
        $sleepMs   = (int) config('nats.pull.sleep_ms', 250);

        // IMPORTANT: create ONE client and reuse it forever.
        $this->client = $this->factory->make();


        while (true) {
            try {
                foreach ($streams as $cfg) {
                    $this->consumeStream($cfg, $batch, $timeoutMs);
                }
            } catch (Throwable $e) {
                Log::error('JetStream consumer outer loop error', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                usleep(self::ERROR_BACKOFF_MS * 1000);
            }

            usleep(max(1, $sleepMs) * 1000);
        }
    }

    /**
     * One-shot pull for a single stream config — used by ConsumeNatsEventsCommand --once.
     * Returns the number of messages attempted (non-null in the batch).
     */
    public function consume(array $streamConfig, ?int $batch = null): int
    {
        $streamName    = (string) ($streamConfig['name'] ?? '');
        $durable       = (string) ($streamConfig['durable'] ?? '');
        $filterSubject = (string) ($streamConfig['filter_subject'] ?? '>');
        $batchSize     = $batch ?? (int) config('nats.pull.batch', 25);
        $timeoutMs     = (int) config('nats.pull.timeout_ms', 2000);

        if ($streamName === '' || $durable === '') {
            return 0;
        }

        if (!$this->client) {
            $this->client = $this->factory->make();
        }

        try {
            $consumer       = $this->getOrInitConsumer($streamName, $durable, $filterSubject);
            $queue          = $consumer->getQueue();
            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));

            if (method_exists($queue, 'setTimeout')) {
                $queue->setTimeout($timeoutSeconds);
            }

            $messages = $queue->fetchAll($batchSize);

            if (empty($messages)) {
                return 0;
            }

            $count = 0;
            foreach ($messages as $msg) {
                if ($msg === null) {
                    continue;
                }
                $this->handleMessage($msg, $streamName, $durable);
                $count++;
            }

            return $count;
        } catch (Throwable $e) {
            Log::error('JetStream consume() error', [
                'stream'    => $streamName,
                'durable'   => $durable,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return 0;
        }
    }

    /**
     * @param array{name:string,durable:string,filter_subject:string} $cfg
     */
    private function consumeStream(array $cfg, int $batch, int $timeoutMs): void
    {
        $streamName    = (string) ($cfg['name'] ?? '');
        $durable       = (string) ($cfg['durable'] ?? '');
        $filterSubject = (string) ($cfg['filter_subject'] ?? '>');

        if ($streamName === '' || $durable === '') {
            throw new Exception('Stream config requires name + durable');
        }

        if (!$this->client) {
            $this->client = $this->factory->make();
        }

        try {
            $consumer = $this->getOrInitConsumer($streamName, $durable, $filterSubject);

            // basis-company/nats.php pull-mode pattern:
            // - consumer->getQueue()->fetchAll($batch)
            $queue = $consumer->getQueue();

            // Library expects timeout in seconds
            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
            if (method_exists($queue, 'setTimeout')) {
                $queue->setTimeout($timeoutSeconds);
            }

            $messages = $queue->fetchAll($batch);

            if (empty($messages)) {
                return;
            }

            foreach ($messages as $msg) {
                if ($msg === null) {
                    continue;
                }

                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            usleep(self::ERROR_BACKOFF_MS * 1000);
        }
    }

    /**
     * Initializes and caches the JetStream consumer client-side object once.
     * NOTE: This does NOT create the consumer on the server; you already did via CLI.
     */
    private function getOrInitConsumer(string $streamName, string $durable, string $filterSubject)
    {
        $key = $streamName . '|' . $durable;

        if (isset($this->consumerCache[$key])) {
            return $this->consumerCache[$key];
        }

        $api    = $this->client->getApi();
        $stream = $api->getStream($streamName);

        $consumer = $stream->getConsumer($durable);

        // Try to set subject filter on client-side config (safe best effort).
        try {
            if (method_exists($consumer, 'getConfiguration')) {
                $cfg = $consumer->getConfiguration();
                if (is_object($cfg) && method_exists($cfg, 'setSubjectFilter')) {
                    $cfg->setSubjectFilter($filterSubject);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed setting consumer subject filter in client (continuing)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
            ]);
        }

        // Some versions require create() to initialize internals; call once only.
        try {
            if (method_exists($consumer, 'create')) {
                $consumer->create();
            }
        } catch (Throwable $e) {
            // If server consumer exists, create() may still be fine; if it throws, we still keep going.
            Log::debug('Consumer create() threw (continuing if server consumer exists)', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->consumerCache[$key] = $consumer;
    }

 
private function handleMessage($msg, string $streamName, string $durable): void
{
    Log::warning('DEBUG_NATS_HANDLE_MESSAGE_REACHED', [
    'class' => __CLASS__,
    'stream' => $streamName,
    'durable' => $durable,
    'msg_class' => is_object($msg) ? get_class($msg) : gettype($msg),
]);
    $msgSubject = $this->getMsgSubject($msg);
    $reply      = $this->getMsgReply($msg);

    // HARD RULE:
    // Real JetStream deliveries ALWAYS have a reply that starts with $JS.ACK.
    // Non-JetStream/internal messages should be ignored, not acked/nacked.
    if (!$this->isJetStreamDelivery($reply)) {
        return;
    }

    $raw = $this->extractBody($msg);

    if ($raw === '') {
        Log::warning('JetStream message has empty payload - ACKed/TERMed', [
            'stream'      => $streamName,
            'consumer'    => $durable,
            'msg_subject' => $msgSubject,
            'reply'       => $reply,
            'class'       => is_object($msg) ? get_class($msg) : gettype($msg),
        ]);

        $this->ackOrTermSafe($msg, $streamName, $durable, 'empty_payload');
        return;
    }

    $event = json_decode($raw, true);

    if (!is_array($event)) {
        Log::warning('JetStream message has non-JSON payload - ACKed/TERMed', [
            'stream'      => $streamName,
            'consumer'    => $durable,
            'msg_subject' => $msgSubject,
            'raw'         => $raw,
        ]);

        $this->ackOrTermSafe($msg, $streamName, $durable, 'non_json_payload');
        return;
    }

    // Support Shape A:
    // {"event_id":"...","event_type":"auth.v1.user.created","data":{...}}
    //
    // Support Shape B:
    // {"id":"...","subject":"auth.v1.user.created","payload":{...}}
    $eventId = (string) ($event['event_id'] ?? $event['id'] ?? '');

    $evtSubject = (string) (
        $event['event_type']
        ?? $event['subject']
        ?? $event['type']
        ?? $msgSubject
        ?? ''
    );

    $data = $event['data'] ?? $event['payload'] ?? [];

    if ($eventId === '' || $evtSubject === '') {
        Log::warning('JetStream message missing event id or subject - ACKed/TERMed', [
            'stream'      => $streamName,
            'consumer'    => $durable,
            'msg_subject' => $msgSubject,
            'event'       => $event,
        ]);

        $this->ackOrTermSafe($msg, $streamName, $durable, 'missing_id_or_subject');
        return;
    }

    // Domain allowlist must be checked against the logical event subject,
    // not only the transport subject. Basis/NATS may not expose msg subject reliably.
    if (!$this->isAllowedDomainSubject($evtSubject)) {
        Log::warning('JetStream event subject not allowed - ACKed/TERMed', [
            'stream'        => $streamName,
            'consumer'      => $durable,
            'msg_subject'   => $msgSubject,
            'event_subject' => $evtSubject,
            'event_id'      => $eventId,
        ]);

        $this->ackOrTermSafe($msg, $streamName, $durable, 'subject_not_allowed');
        return;
    }

    if (!is_array($data)) {
        Log::warning('JetStream event data/payload is not an array - ACKed/TERMed', [
            'stream'        => $streamName,
            'consumer'      => $durable,
            'msg_subject'   => $msgSubject,
            'event_subject' => $evtSubject,
            'event_id'      => $eventId,
            'data_type'     => gettype($data),
        ]);

        $this->ackOrTermSafe($msg, $streamName, $durable, 'invalid_event_data');
        return;
    }

    DB::beginTransaction();

    try {
        // Idempotency + attempts counter.
        $inbox = InboxEvent::query()
            ->where('event_id', $eventId)
            ->lockForUpdate()
            ->first();

        if (!$inbox) {
            InboxEvent::query()->create([
                'event_id'   => $eventId,
                'subject'    => $evtSubject,
                'event_type' => $evtSubject,
                'payload'    => $event,
                'status'     => InboxEventStatus::Pending,
                'attempts'   => 0,
            ]);

            $inbox = InboxEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();
        }

        if (!$inbox) {
           throw new \RuntimeException('Inbox event row could not be created or reloaded.');
        }

        // If parked, never retry.
        if ($inbox->status === InboxEventStatus::Parked) {
            DB::commit();

            $this->ackOrTermSafe($msg, $streamName, $durable, 'already_parked');

            Log::warning('Event is parked - ACKed/TERMed and skipped', [
                'stream'       => $streamName,
                'consumer'     => $durable,
                'event_id'     => $eventId,
                'subject'      => $evtSubject,
                'attempts'     => (int) $inbox->attempts,
                'parked_since' => $inbox->failed_at?->toDateTimeString(),
            ]);

            return;
        }

        // If already processed, ACK and exit.
        if ($inbox->status === InboxEventStatus::Processed) {
            DB::commit();

            $this->ackOrTermSafe($msg, $streamName, $durable, 'already_processed');
            return;
        }

        $handlerClass = $this->router->resolve($evtSubject);

        /** @var EventHandlerInterface $handler */
        $handler = app($handlerClass);
        $handler->handle($data);

        $inbox->status       = InboxEventStatus::Processed;
        $inbox->processed_at = now();
        $inbox->failed_at    = null;
        $inbox->last_error   = null;
        $inbox->save();

        DB::commit();

        $this->ackOrTermSafe($msg, $streamName, $durable, 'processed_ok');

        Log::info('JetStream event processed successfully', [
            'stream'        => $streamName,
            'consumer'      => $durable,
            'msg_subject'   => $msgSubject,
            'event_subject' => $evtSubject,
            'event_id'      => $eventId,
            'handler'       => $handlerClass,
        ]);
    } catch (Throwable $e) {
        try {
            $locked = InboxEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($locked) {
                $locked->attempts   = (int) $locked->attempts + 1;
                $locked->last_error = $e->getMessage();

                if ($locked->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                    $locked->status    = InboxEventStatus::Parked;
                    $locked->failed_at = now();
                    $locked->save();

                    DB::commit();

                    $this->ackOrTermSafe($msg, $streamName, $durable, 'parked_max_attempts');

                    Log::error('JetStream event parked after max attempts', [
                        'stream'        => $streamName,
                        'consumer'      => $durable,
                        'msg_subject'   => $msgSubject,
                        'event_subject' => $evtSubject,
                        'event_id'      => $eventId,
                        'error'         => $e->getMessage(),
                    ]);

                    return;
                }

                $locked->save();

                DB::commit();

                $this->nackWithDelaySafe(
                    $msg,
                    $streamName,
                    $durable,
                    self::NACK_DELAY_SECONDS,
                    'handler_failed_retry'
                );

                Log::warning('JetStream event failed - NACKed for retry', [
                    'stream'        => $streamName,
                    'consumer'      => $durable,
                    'msg_subject'   => $msgSubject,
                    'event_subject' => $evtSubject,
                    'event_id'      => $eventId,
                    'attempts'      => (int) $locked->attempts,
                    'error'         => $e->getMessage(),
                ]);

                return;
            }

            DB::rollBack();

            $this->nackWithDelaySafe(
                $msg,
                $streamName,
                $durable,
                self::NACK_DELAY_SECONDS,
                'missing_inbox_row'
            );

            Log::error('JetStream event failed but inbox row was missing - NACKed', [
                'stream'        => $streamName,
                'consumer'      => $durable,
                'msg_subject'   => $msgSubject,
                'event_subject' => $evtSubject,
                'event_id'      => $eventId,
                'error'         => $e->getMessage(),
            ]);
        } catch (Throwable $inner) {
            DB::rollBack();

            $this->nackWithDelaySafe(
                $msg,
                $streamName,
                $durable,
                self::NACK_DELAY_SECONDS,
                'attempt_update_failed'
            );

            Log::error('JetStream event failed and attempts could not be updated - NACKed', [
                'stream'                => $streamName,
                'consumer'              => $durable,
                'msg_subject'           => $msgSubject,
                'event_subject'         => $evtSubject,
                'event_id'              => $eventId,
                'original_error'        => $e->getMessage(),
                'attempts_update_error' => $inner->getMessage(),
            ]);
        }
    }
}



    private function isJetStreamDelivery(?string $reply): bool
    {
        return is_string($reply) && str_starts_with($reply, '$JS.ACK.');
    }

    private function isAllowedDomainSubject(?string $subject): bool
    {
        if (!is_string($subject) || $subject === '') return false;

        foreach (self::SUBJECT_ALLOW_PREFIXES as $prefix) {
            if ($prefix !== '' && str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Most common for Basis\Nats\Message\Msg is: public string $payload
     * Your previous extractor was missing this, which is why you saw payload_len=0.
     */
    private function extractBody($msg): string
    {
        try {
            if (!is_object($msg)) return '';

            if (property_exists($msg, 'payload') && is_string($msg->payload)) {
                return $msg->payload;
            }

            if (property_exists($msg, 'body') && is_string($msg->body)) {
                return $msg->body;
            }

            if (method_exists($msg, 'getBody')) {
                $b = $msg->getBody();
                if (is_string($b)) return $b;
            }

            if (method_exists($msg, '__toString')) {
                $s = (string) $msg;
                return $s !== '' ? $s : '';
            }
        } catch (Throwable $e) {
            // ignore
        }

        return '';
    }

    private function payloadLenSafe($msg): int
    {
        try {
            $raw = $this->extractBody($msg);
            return strlen($raw);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function getMsgReply($msg): ?string
    {
        try {
            if (!is_object($msg)) return null;

            foreach (['replyTo', 'reply_to', 'reply', 'replySubject', 'reply_subject'] as $k) {
                if (property_exists($msg, $k) && is_string($msg->{$k}) && $msg->{$k} !== '') {
                    return $msg->{$k};
                }
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    private function getMsgSubject($msg): ?string
    {
        try {
            if (is_object($msg) && property_exists($msg, 'subject') && is_string($msg->subject)) {
                return $msg->subject;
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    /**
     * ACK sometimes throws in Basis if internal ack object cannot be built.
     * TERM (if available) is a safe fallback for poison messages.
     */
    private function ackOrTermSafe($msg, string $streamName, string $durable, string $reason): void
    {
        try {
            if (method_exists($msg, 'ack')) {
                $msg->ack();

                return;
            }
        } catch (Throwable $e) {
            Log::warning('ACK failed (will try TERM if available)', [
                'stream'    => $streamName,
                'consumer'  => $durable,
                'reason'    => $reason,
                'subject'   => $this->getMsgSubject($msg),
                'reply'     => $this->getMsgReply($msg),
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        try {
            if (method_exists($msg, 'term')) {
                $msg->term();
            }
        } catch (Throwable $e) {
            Log::warning('TERM failed', [
                'stream'    => $streamName,
                'consumer'  => $durable,
                'reason'    => $reason,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * basis-company/nats.php uses nack($delaySeconds).
     * Some older code uses nak(). We support both.
     */
    private function nackWithDelaySafe($msg, string $streamName, string $durable, int $delaySeconds, string $reason): void
    {
        try {
            if (method_exists($msg, 'nack')) {
                $msg->nack($delaySeconds);

                return;
            }

            if (method_exists($msg, 'nak')) {
                // No delay support on nak()
                $msg->nak();
            }
        } catch (Throwable $e) {
            Log::warning('NACK/NAK failed', [
                'stream'    => $streamName,
                'consumer'  => $durable,
                'reason'    => $reason,
                'subject'   => $this->getMsgSubject($msg),
                'reply'     => $this->getMsgReply($msg),
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
