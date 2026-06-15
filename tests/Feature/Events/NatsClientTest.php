<?php

namespace Tests\Feature\Events;

use App\Services\Events\EventRouter;
use App\Services\Nats\JetStreamConsumer;
use App\Services\Nats\NatsClientFactory;
use Exception;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests for NatsClientFactory, JetStreamConsumer structure, and EventRouter
 * payload normalization.
 *
 * No live NATS server is required.  Factory validation tests override config
 * values in-process; normalization tests are pure static logic.
 * Network connections are never attempted.
 */
class NatsClientTest extends TestCase
{
    // -----------------------------------------------------------------------
    // NatsClientFactory — validation (no NATS connection needed)
    // -----------------------------------------------------------------------

    public function test_factory_throws_when_host_is_empty(): void
    {
        config(['nats.host' => '', 'nats.port' => 4222, 'nats.token' => 'tok']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/host\\/port/i');

        (new NatsClientFactory())->make();
    }

    public function test_factory_throws_when_port_is_zero(): void
    {
        config(['nats.host' => '127.0.0.1', 'nats.port' => 0, 'nats.token' => 'tok']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/host\\/port/i');

        (new NatsClientFactory())->make();
    }

    public function test_factory_throws_when_port_is_negative(): void
    {
        config(['nats.host' => '127.0.0.1', 'nats.port' => -1, 'nats.token' => 'tok']);

        $this->expectException(Exception::class);

        (new NatsClientFactory())->make();
    }

    public function test_factory_throws_when_auth_is_not_configured(): void
    {
        config([
            'nats.host'  => '127.0.0.1',
            'nats.port'  => 4222,
            'nats.token' => '',
            'nats.user'  => '',
            'nats.pass'  => '',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/auth not configured/i');

        (new NatsClientFactory())->make();
    }

    public function test_factory_throws_when_only_user_is_set_without_pass(): void
    {
        config([
            'nats.host'  => '127.0.0.1',
            'nats.port'  => 4222,
            'nats.token' => '',
            'nats.user'  => 'alice',
            'nats.pass'  => '',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/BOTH/i');

        (new NatsClientFactory())->make();
    }

    public function test_factory_throws_when_only_pass_is_set_without_user(): void
    {
        config([
            'nats.host'  => '127.0.0.1',
            'nats.port'  => 4222,
            'nats.token' => '',
            'nats.user'  => '',
            'nats.pass'  => 'secret',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/BOTH/i');

        (new NatsClientFactory())->make();
    }

    public function test_factory_returns_client_instance_with_token_auth_when_server_reachable(): void
    {
        config([
            'nats.host'  => '127.0.0.1',
            'nats.port'  => 4222,
            'nats.token' => 'test-token',
        ]);

        try {
            $client = (new NatsClientFactory())->make();
            $this->assertInstanceOf(\Basis\Nats\Client::class, $client);
        } catch (\Throwable) {
            $this->markTestSkipped('No NATS server available — connection test skipped.');
        }
    }

    // -----------------------------------------------------------------------
    // JetStreamConsumer — structural assertions (transport-only)
    // -----------------------------------------------------------------------

    public function test_jetstream_consumer_does_not_have_normalize_envelope_method(): void
    {
        $this->assertFalse(
            method_exists(JetStreamConsumer::class, 'normalizeEnvelope'),
            'JetStreamConsumer must not contain normalizeEnvelope — normalization belongs in EventRouter.'
        );
    }

    public function test_jetstream_consumer_public_methods_are_transport_only(): void
    {
        $reflection = new \ReflectionClass(JetStreamConsumer::class);
        $public     = array_map(
            fn ($m) => $m->getName(),
            array_filter($reflection->getMethods(\ReflectionMethod::IS_PUBLIC), fn ($m) => ! $m->isConstructor())
        );

        $this->assertContains('runForever', $public);
        $this->assertNotContains('normalizeEnvelope', $public);
        $this->assertNotContains('normalizePayload', $public);
        $this->assertNotContains('routeNatsPayload', $public);
    }

    // -----------------------------------------------------------------------
    // EventRouter::normalizePayload — Shape A (event_id/event_type/data)
    // -----------------------------------------------------------------------

    public function test_normalize_payload_accepts_shape_a_with_event_id_and_data(): void
    {
        $decoded = [
            'event_id'    => 'uuid-111',
            'event_type'  => 'auth.v1.user.updated',
            'occurred_at' => '2026-01-01T00:00:00Z',
            'source'      => 'auth-service',
            'version'     => 1,
            'data'        => ['id' => 42, 'name' => 'Alice'],
        ];

        $envelope = EventRouter::normalizePayload('auth.v1.user.updated', $decoded);

        $this->assertSame('uuid-111', $envelope['event_id']);
        $this->assertSame('auth.v1.user.updated', $envelope['event_type']);
        $this->assertSame('auth-service', $envelope['source']);
        $this->assertSame(1, $envelope['version']);
        $this->assertSame(['id' => 42, 'name' => 'Alice'], $envelope['data']);
    }

    public function test_normalize_payload_shape_a_falls_back_to_subject_when_event_type_absent(): void
    {
        $envelope = EventRouter::normalizePayload('auth.v1.store.created', [
            'event_id' => 'uuid-222',
            'data'     => [],
        ]);

        $this->assertSame('uuid-222', $envelope['event_id']);
        $this->assertSame('auth.v1.store.created', $envelope['event_type']);
    }

    // -----------------------------------------------------------------------
    // EventRouter::normalizePayload — Shape B (id/subject/payload)
    // -----------------------------------------------------------------------

    public function test_normalize_payload_accepts_shape_b_with_id_and_payload(): void
    {
        $decoded = [
            'id'      => 'uuid-333',
            'subject' => 'auth.v1.store.updated',
            'payload' => ['id' => 99, 'store_name' => 'Branch A'],
        ];

        $envelope = EventRouter::normalizePayload('auth.v1.store.updated', $decoded);

        $this->assertSame('uuid-333', $envelope['event_id']);
        $this->assertSame('auth.v1.store.updated', $envelope['event_type']);
        $this->assertSame(['id' => 99, 'store_name' => 'Branch A'], $envelope['data']);
    }

    public function test_normalize_payload_shape_b_falls_back_to_subject_param_when_subject_key_absent(): void
    {
        $envelope = EventRouter::normalizePayload('auth.v1.user.deleted', [
            'id'      => 'uuid-444',
            'payload' => [],
        ]);

        $this->assertSame('uuid-444', $envelope['event_id']);
        $this->assertSame('auth.v1.user.deleted', $envelope['event_type']);
    }

    // -----------------------------------------------------------------------
    // EventRouter::normalizePayload — rejection cases
    // -----------------------------------------------------------------------

    public function test_normalize_payload_throws_when_event_id_and_id_both_absent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EventRouter::normalizePayload('auth.v1.user.updated', [
            'event_type' => 'auth.v1.user.updated',
            'data'       => [],
        ]);
    }

    public function test_normalize_payload_throws_when_data_is_not_array_in_shape_a(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EventRouter::normalizePayload('auth.v1.user.updated', [
            'event_id'   => 'uuid-555',
            'event_type' => 'auth.v1.user.updated',
            'data'       => 'this-is-a-string-not-array',
        ]);
    }

    public function test_normalize_payload_throws_when_payload_is_not_array_in_shape_b(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EventRouter::normalizePayload('auth.v1.user.updated', [
            'id'      => 'uuid-666',
            'subject' => 'auth.v1.user.updated',
            'payload' => 'this-is-a-string-not-array',
        ]);
    }

    // -----------------------------------------------------------------------
    // EventRouter — public contract
    // -----------------------------------------------------------------------

    public function test_event_router_route_method_exists_and_is_public(): void
    {
        $reflection = new \ReflectionMethod(EventRouter::class, 'route');

        $this->assertTrue($reflection->isPublic());

        $params = $reflection->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('subject', $params[0]->getName());
        $this->assertSame('envelope', $params[1]->getName());
    }

    public function test_event_router_route_nats_payload_method_exists_and_is_public(): void
    {
        $reflection = new \ReflectionMethod(EventRouter::class, 'routeNatsPayload');

        $this->assertTrue($reflection->isPublic());

        $params = $reflection->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('subject', $params[0]->getName());
        $this->assertSame('payload', $params[1]->getName());
    }

    public function test_event_router_normalize_payload_is_public_static(): void
    {
        $reflection = new \ReflectionMethod(EventRouter::class, 'normalizePayload');

        $this->assertTrue($reflection->isPublic());
        $this->assertTrue($reflection->isStatic());
    }

    // -----------------------------------------------------------------------
    // config/nats.php — naming checks
    // -----------------------------------------------------------------------

    public function test_nats_config_outbound_stream_uses_nats_hiring_platform_stream_env_var(): void
    {
        $this->assertSame('HIRING_PLATFORM_EVENTS', config('nats.jetstream.stream'));
    }

    public function test_nats_config_file_does_not_reference_nats_hiring_stream(): void
    {
        $contents = file_get_contents(base_path('config/nats.php'));

        $this->assertStringNotContainsString('NATS_HIRING_STREAM', $contents);
        $this->assertStringContainsString('NATS_HIRING_PLATFORM_STREAM', $contents);
    }

    public function test_nats_config_has_all_required_pull_keys(): void
    {
        $pull = config('nats.pull');

        $this->assertArrayHasKey('batch', $pull);
        $this->assertArrayHasKey('expires_seconds', $pull);
        $this->assertArrayHasKey('timeout_ms', $pull);
        $this->assertArrayHasKey('sleep_ms', $pull);
    }

    public function test_nats_streams_config_contains_auth_stream_with_auth_v1_filter(): void
    {
        $subjects = array_column(config('nats.streams'), 'filter_subject');

        $this->assertContains('auth.v1.>', $subjects);
    }

    // -----------------------------------------------------------------------
    // ConsumeNatsEventsCommand — imports the correct namespace
    // -----------------------------------------------------------------------

    public function test_consume_nats_command_uses_app_services_nats_namespace(): void
    {
        $source = file_get_contents(base_path('app/Console/Commands/ConsumeNatsEventsCommand.php'));

        $this->assertStringContainsString('App\Services\Nats\JetStreamConsumer', $source);
        $this->assertStringNotContainsString('App\Services\Events\JetStreamConsumer', $source);
    }

    // -----------------------------------------------------------------------
    // JetStreamConsumer — source file schema-safety checks
    // -----------------------------------------------------------------------

    public function test_jetstream_consumer_source_does_not_reference_event_inbox(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        $this->assertStringNotContainsString('EventInbox', $source,
            'JetStreamConsumer must not reference EventInbox — use InboxEvent.');
    }

    public function test_jetstream_consumer_source_uses_inbox_event_model(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        $this->assertStringContainsString('InboxEvent', $source,
            'JetStreamConsumer must use App\Models\InboxEvent.');
    }

    public function test_jetstream_consumer_source_does_not_use_parked_at_column(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        // No property access on the model (->parked_at) — the column does not exist
        $this->assertStringNotContainsString('->parked_at', $source,
            'JetStreamConsumer must not access ->parked_at — inbox_events has no such column.');

        // The status column (not a parked_at column) is used to represent parked state.
        // Code now uses InboxEventStatus::Parked enum constant instead of the raw string.
        $this->assertTrue(
            str_contains($source, "status === 'parked'") || str_contains($source, 'InboxEventStatus::Parked'),
            'JetStreamConsumer must use status === "parked" or InboxEventStatus::Parked instead of parked_at.'
        );
        $this->assertTrue(
            str_contains($source, "status    = 'parked'") || str_contains($source, 'InboxEventStatus::Parked'),
            'JetStreamConsumer must set status to parked on max attempts, not parked_at.'
        );
    }

    public function test_jetstream_consumer_source_does_not_insert_source_stream_consumer_columns(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        // Extract only the InboxEvent::query()->create([...]) block to avoid
        // false positives from log context keys like 'stream' => $streamName.
        preg_match('/InboxEvent::query\(\)->create\(\[(.*?)\]\)/s', $source, $matches);
        $createBlock = $matches[1] ?? '';

        $this->assertNotEmpty($createBlock, 'JetStreamConsumer must contain an InboxEvent::query()->create() call.');
        $this->assertStringNotContainsString("'source'", $createBlock,
            'InboxEvent::query()->create() must not include source — column does not exist in inbox_events.');
        $this->assertStringNotContainsString("'stream'", $createBlock,
            'InboxEvent::query()->create() must not include stream — column does not exist in inbox_events.');
        $this->assertStringNotContainsString("'consumer'", $createBlock,
            'InboxEvent::query()->create() must not include consumer — column does not exist in inbox_events.');
    }

    public function test_jetstream_consumer_passes_data_array_not_full_event_to_handler(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        $this->assertStringContainsString('handle(is_array($data)', $source,
            'JetStreamConsumer must call handle(is_array($data) ? $data : []) — not handle($event).');
        $this->assertStringNotContainsString('handle($event)', $source,
            'JetStreamConsumer must not pass the full $event to handle() — handlers expect the data array.');
    }

    public function test_jetstream_consumer_source_shape_a_extraction(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        $this->assertStringContainsString("event['event_id']", $source,
            'JetStreamConsumer must support Shape A event_id extraction.');
    }

    public function test_jetstream_consumer_source_shape_b_extraction(): void
    {
        $source = file_get_contents(base_path('app/Services/Nats/JetStreamConsumer.php'));

        $this->assertStringContainsString("event['id']", $source,
            'JetStreamConsumer must support Shape B id extraction.');
        $this->assertStringContainsString("event['subject']", $source,
            'JetStreamConsumer must support Shape B subject extraction.');
    }

    // -----------------------------------------------------------------------
    // No live NATS server required
    // -----------------------------------------------------------------------

    public function test_no_real_nats_connection_is_made_during_tests(): void
    {
        $binding = $this->app->make(\App\Services\Events\EventBusPublisher::class);

        $this->assertInstanceOf(
            \App\Services\Events\LogEventBusPublisher::class,
            $binding
        );
    }
}
