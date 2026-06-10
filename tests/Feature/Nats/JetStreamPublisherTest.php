<?php

namespace Tests\Feature\Nats;

use App\Services\Nats\JetStreamPublisher;
use App\Services\Nats\NatsClientFactory;
use Basis\Nats\Api;
use Basis\Nats\Client;
use Basis\Nats\Stream\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class JetStreamPublisherTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePublisher(?NatsClientFactory $factory = null): JetStreamPublisher
    {
        return new JetStreamPublisher($factory ?? $this->makeFactory());
    }

    private function makeFactory(bool $putSucceeds = true): NatsClientFactory
    {
        $stream = Mockery::mock(Stream::class);

        if ($putSucceeds) {
            $stream->shouldReceive('put')->andReturnSelf();
        } else {
            $stream->shouldReceive('put')->andThrow(new RuntimeException('NATS publish failed'));
        }

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('getStream')->andReturn($stream);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getApi')->andReturn($api);

        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);

        return $factory;
    }

    // -----------------------------------------------------------------------
    // Subject allowlist validation
    // -----------------------------------------------------------------------

    public function test_hiring_v1_application_hired_is_allowed(): void
    {
        $publisher = $this->makePublisher();

        $result = $publisher->publish('hiring.v1.application.hired', ['test' => true]);

        $this->assertIsArray($result);
    }

    public function test_hiring_v1_application_rejected_is_allowed(): void
    {
        $publisher = $this->makePublisher();

        $result = $publisher->publish('hiring.v1.application.rejected', ['test' => true]);

        $this->assertIsArray($result);
    }

    public function test_any_hiring_v1_subject_is_allowed(): void
    {
        $publisher = $this->makePublisher();

        $publisher->publish('hiring.v1.application.stage_changed', ['test' => true]);
        $publisher->publish('hiring.v1.some.deep.subject', ['test' => true]);

        $this->assertTrue(true);
    }

    public function test_auth_subject_is_rejected(): void
    {
        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldNotReceive('make');

        $publisher = new JetStreamPublisher($factory);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auth.v1.user.created');

        $publisher->publish('auth.v1.user.created', []);
    }

    public function test_arbitrary_subject_is_rejected(): void
    {
        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldNotReceive('make');

        $publisher = new JetStreamPublisher($factory);

        $this->expectException(InvalidArgumentException::class);

        $publisher->publish('random.subject', []);
    }

    public function test_subject_without_hiring_prefix_is_rejected(): void
    {
        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldNotReceive('make');

        $publisher = new JetStreamPublisher($factory);

        $this->expectException(InvalidArgumentException::class);

        $publisher->publish('hiring.subject', []); // no "v1." prefix
    }

    // -----------------------------------------------------------------------
    // JetStream enabled check
    // -----------------------------------------------------------------------

    public function test_throws_when_jetstream_disabled(): void
    {
        config(['nats.jetstream.enabled' => false]);

        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldNotReceive('make');

        $publisher = new JetStreamPublisher($factory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JetStream is disabled');

        $publisher->publish('hiring.v1.application.hired', []);
    }

    public function test_enabled_by_default(): void
    {
        config(['nats.jetstream.enabled' => true]);

        $publisher = $this->makePublisher();

        $publisher->publish('hiring.v1.application.hired', ['ok' => true]);

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Client is called with correct arguments
    // -----------------------------------------------------------------------

    public function test_publishes_to_correct_stream_and_subject(): void
    {
        $stream = Mockery::mock(Stream::class);
        $stream->shouldReceive('put')
            ->once()
            ->with('hiring.v1.application.hired', Mockery::type('string'))
            ->andReturnSelf();

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('getStream')
            ->once()
            ->with('HIRING_PLATFORM_EVENTS')
            ->andReturn($stream);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getApi')->once()->andReturn($api);

        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);

        $publisher = new JetStreamPublisher($factory);
        $publisher->publish('hiring.v1.application.hired', ['application_id' => 42]);
    }

    public function test_payload_is_json_encoded(): void
    {
        $payload = ['application_id' => 42, 'status' => 'hired'];

        $stream = Mockery::mock(Stream::class);
        $stream->shouldReceive('put')
            ->once()
            ->with(Mockery::any(), json_encode($payload))
            ->andReturnSelf();

        $api = Mockery::mock(Api::class);
        $api->shouldReceive('getStream')->andReturn($stream);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getApi')->andReturn($api);

        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);

        $publisher = new JetStreamPublisher($factory);
        $publisher->publish('hiring.v1.application.hired', $payload);
    }

    public function test_publish_returns_array(): void
    {
        $publisher = $this->makePublisher();

        $result = $publisher->publish('hiring.v1.application.hired', ['ok' => true]);

        $this->assertIsArray($result);
    }

    // -----------------------------------------------------------------------
    // No live NATS required — confirmed by never calling make() on bad subjects
    // -----------------------------------------------------------------------

    public function test_no_live_nats_required_for_allowlist_rejection(): void
    {
        $factory = Mockery::mock(NatsClientFactory::class);
        $factory->shouldNotReceive('make');

        $publisher = new JetStreamPublisher($factory);

        try {
            $publisher->publish('bad.subject', []);
        } catch (InvalidArgumentException) {
        }

        $this->assertTrue(true);
    }
}
