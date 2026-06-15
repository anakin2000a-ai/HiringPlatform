<?php

namespace Tests\Feature\Events;

use App\Models\InboxEvent;
use App\Services\Events\EventRouter;
use App\Services\Nats\JetStreamConsumer;
use App\Services\Nats\NatsClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runtime behaviour tests for JetStreamConsumer::handleMessage().
 *
 * No live NATS server is required.  All tests use a mock Msg object with
 * a real $JS.ACK. reply so the JetStream delivery guard passes, and exercise
 * the DB idempotency logic directly via ReflectionMethod.
 */
class JetStreamConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM   = 'TEST_STREAM';
    private const DURABLE  = 'test-consumer';
    private const JS_REPLY = '$JS.ACK.TEST_STREAM.test-consumer.1.2.3.4.5';

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function consumer(): JetStreamConsumer
    {
        $factory = $this->createMock(NatsClientFactory::class);
        $router  = $this->app->make(EventRouter::class);

        return new JetStreamConsumer($factory, $router);
    }

    private function mockMsg(string $subject, string $replyTo, string $body): object
    {
        return new class($subject, $replyTo, $body) {
            public string $subject;
            public string $replyTo;
            public string $payload;
            public bool   $acked  = false;
            public bool   $termed = false;
            public bool   $nacked = false;
            public string $termReason = '';

            public function __construct(string $s, string $r, string $b)
            {
                $this->subject = $s;
                $this->replyTo = $r;
                $this->payload = $b;
            }

            public function ack(): void { $this->acked = true; }

            public function term(string $reason = ''): void
            {
                $this->termed     = true;
                $this->termReason = $reason;
            }

            public function nack(float $delay = 0): void { $this->nacked = true; }
        };
    }

    private function callHandleMessage(JetStreamConsumer $consumer, object $msg): void
    {
        $ref = new \ReflectionMethod(JetStreamConsumer::class, 'handleMessage');
        $ref->invoke($consumer, $msg, self::STREAM, self::DURABLE);
    }

    // -----------------------------------------------------------------------
    // Shape A — event_id / event_type / data
    // -----------------------------------------------------------------------

    public function test_shape_a_message_is_acked_and_inbox_row_marked_processed(): void
    {
        $eventPayload = [
            'event_id'   => 'shape-a-uuid-001',
            'event_type' => 'auth.v1.store.deleted',
            'data'       => ['id' => 9999],
        ];

        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            self::JS_REPLY,
            json_encode($eventPayload),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertTrue($msg->acked, 'Shape A message should be ACKed after successful processing.');
        $this->assertFalse($msg->termed);
        $this->assertFalse($msg->nacked);

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => 'shape-a-uuid-001',
            'subject'  => 'auth.v1.store.deleted',
            'status'   => 'processed',
        ]);

        $row = InboxEvent::where('event_id', 'shape-a-uuid-001')->first();
        $this->assertNotNull($row->processed_at);
        $this->assertNull($row->failed_at);
        $this->assertNull($row->last_error);
    }

    // -----------------------------------------------------------------------
    // Shape B — id / subject / payload
    // -----------------------------------------------------------------------

    public function test_shape_b_message_is_acked_and_inbox_row_marked_processed(): void
    {
        $eventPayload = [
            'id'      => 'shape-b-uuid-002',
            'subject' => 'auth.v1.store.deleted',
            'payload' => ['id' => 8888],
        ];

        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            self::JS_REPLY,
            json_encode($eventPayload),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertTrue($msg->acked, 'Shape B message should be ACKed after successful processing.');

        $this->assertDatabaseHas('inbox_events', [
            'event_id' => 'shape-b-uuid-002',
            'subject'  => 'auth.v1.store.deleted',
            'status'   => 'processed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Handler receives the data array, not the full event
    // -----------------------------------------------------------------------

    public function test_handler_receives_data_array_not_full_event(): void
    {
        // Use auth.v1.store.deleted: handler calls StoreDeletedHandler::handle($data)
        // which does Store::find($data['id']). If $data is the full event (has 'event_id'),
        // it would behave differently from receiving just ['id' => ...].
        // We verify via InboxEvent status (processed) that the handler ran without error.
        $eventPayload = [
            'event_id'   => 'handler-data-test-003',
            'event_type' => 'auth.v1.store.deleted',
            'data'       => ['id' => 7777],
        ];

        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            self::JS_REPLY,
            json_encode($eventPayload),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertTrue($msg->acked);
        $this->assertDatabaseHas('inbox_events', [
            'event_id' => 'handler-data-test-003',
            'status'   => 'processed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Idempotency — processed event is skipped / ACKed
    // -----------------------------------------------------------------------

    public function test_already_processed_event_is_acked_without_re_running_handler(): void
    {
        InboxEvent::create([
            'event_id'     => 'already-processed-uuid-004',
            'subject'      => 'auth.v1.store.deleted',
            'event_type'   => 'auth.v1.store.deleted',
            'payload'      => ['id' => 'already-processed-uuid-004'],
            'status'       => 'processed',
            'attempts'     => 1,
            'processed_at' => now(),
        ]);

        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            self::JS_REPLY,
            json_encode(['id' => 'already-processed-uuid-004', 'subject' => 'auth.v1.store.deleted', 'payload' => []]),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertTrue($msg->acked || $msg->termed, 'Already-processed event must be ACKed or TERMed.');
        $this->assertFalse($msg->nacked);
        // Row must remain processed, not reset to pending
        $this->assertDatabaseHas('inbox_events', [
            'event_id' => 'already-processed-uuid-004',
            'status'   => 'processed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Idempotency — parked event is skipped / ACKed
    // -----------------------------------------------------------------------

    public function test_parked_event_is_acked_and_not_retried(): void
    {
        InboxEvent::create([
            'event_id'   => 'parked-uuid-005',
            'subject'    => 'auth.v1.store.deleted',
            'event_type' => 'auth.v1.store.deleted',
            'payload'    => ['id' => 'parked-uuid-005'],
            'status'     => 'parked',
            'attempts'   => 5,
            'failed_at'  => now(),
            'last_error' => 'simulated failure',
        ]);

        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            self::JS_REPLY,
            json_encode(['id' => 'parked-uuid-005', 'subject' => 'auth.v1.store.deleted', 'payload' => []]),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertTrue($msg->acked || $msg->termed, 'Parked event must be ACKed or TERMed, not NACKed.');
        $this->assertFalse($msg->nacked);
        // Row must remain parked with 5 attempts
        $this->assertDatabaseHas('inbox_events', [
            'event_id' => 'parked-uuid-005',
            'status'   => 'parked',
            'attempts' => 5,
        ]);
    }

    // -----------------------------------------------------------------------
    // Max attempts — parks the event using status='parked' and failed_at
    // -----------------------------------------------------------------------

    public function test_max_attempts_sets_status_parked_and_failed_at(): void
    {
        // Pre-create inbox row at MAX_PROCESSING_ATTEMPTS - 1 attempts so the
        // next failure tips it over the threshold.
        InboxEvent::create([
            'event_id'   => 'max-attempts-uuid-006',
            'subject'    => 'auth.v1.store.deleted',
            'event_type' => 'auth.v1.store.deleted',
            'payload'    => [],
            'status'     => 'pending',
            'attempts'   => 4, // MAX_PROCESSING_ATTEMPTS = 5; next failure hits >= 5
            'last_error' => 'previous error',
        ]);

        // Use an unknown subject that still passes the allowlist ('auth.v1.' prefix)
        // but whose handler intentionally throws.  We'll use a known subject but
        // make the router resolve to a throwing handler by using a custom subject
        // that the NoOpHandler will handle — wait, NoOpHandler doesn't throw.
        //
        // Instead: use a subject that resolves to a handler whose handle() throws
        // when given specific data.  The simplest approach: point to a non-existent
        // class via a subject that hits the default (NoOpHandler) — NoOpHandler never
        // throws.  We need an actual failure path.
        //
        // The cleanest approach without adding test infrastructure: directly set the
        // inbox row to attempts = MAX_PROCESSING_ATTEMPTS - 1, then submit a message
        // whose decoded payload passes validation but whose subject's handler throws.
        // We'll use Mockery to swap the router.

        $factory = $this->createMock(NatsClientFactory::class);

        $router = $this->getMockBuilder(EventRouter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $router->method('resolve')
            ->willThrowException(new \RuntimeException('Simulated handler failure for max-attempts test'));

        $consumer = new JetStreamConsumer($factory, $router);

        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            self::JS_REPLY,
            json_encode(['id' => 'max-attempts-uuid-006', 'subject' => 'auth.v1.store.deleted', 'payload' => []]),
        );

        $this->callHandleMessage($consumer, $msg);

        // After this failure: attempts becomes 5 >= MAX_PROCESSING_ATTEMPTS(5) → parked
        $this->assertTrue($msg->acked || $msg->termed,
            'After max attempts the message must be ACKed or TERMed, not NACKed.');

        $row = InboxEvent::where('event_id', 'max-attempts-uuid-006')->first();
        $this->assertNotNull($row);
        $this->assertSame('parked', $row->status instanceof \BackedEnum ? $row->status->value : $row->status);
        $this->assertNotNull($row->failed_at);
        $this->assertNotNull($row->last_error);
    }

    // -----------------------------------------------------------------------
    // Non-JetStream reply is silently ignored (no ack/term/nack)
    // -----------------------------------------------------------------------

    public function test_message_without_js_ack_reply_is_ignored(): void
    {
        $msg = $this->mockMsg(
            'auth.v1.store.deleted',
            '',   // no $JS.ACK. prefix
            json_encode(['id' => 'no-js-reply', 'subject' => 'auth.v1.store.deleted', 'payload' => []]),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertFalse($msg->acked);
        $this->assertFalse($msg->termed);
        $this->assertFalse($msg->nacked);
        $this->assertDatabaseMissing('inbox_events', ['event_id' => 'no-js-reply']);
    }

    // -----------------------------------------------------------------------
    // Non-allowed subject is TERMed / ACKed
    // -----------------------------------------------------------------------

    public function test_non_allowed_subject_is_acked_or_termed(): void
    {
        $msg = $this->mockMsg(
            'handler.internal.junk',
            self::JS_REPLY,
            json_encode(['id' => 'junk-subject', 'subject' => 'handler.internal.junk', 'payload' => []]),
        );

        $this->callHandleMessage($this->consumer(), $msg);

        $this->assertTrue($msg->acked || $msg->termed,
            'Non-allowed subject must be ACKed or TERMed to stop redelivery.');
        $this->assertFalse($msg->nacked);
    }
}
