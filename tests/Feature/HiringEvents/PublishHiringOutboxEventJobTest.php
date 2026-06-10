<?php

namespace Tests\Feature\HiringEvents;

use App\Jobs\PublishHiringOutboxEventJob;
use App\Models\OutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PublishHiringOutboxEventJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeOutboxEvent(array $overrides = []): OutboxEvent
    {
        return OutboxEvent::create(array_merge([
            'event_id'   => (string) Str::ulid(),
            'event_type' => 'hiring.v1.application.hired',
            'subject'    => 'hiring.v1.application.hired',
            'payload'    => ['id' => (string) Str::ulid(), 'data' => []],
            'status'     => 'pending',
            'attempts'   => 0,
        ], $overrides));
    }

    private function mockPublisher(bool $succeed = true, ?string $errorMessage = null): JetStreamPublisher
    {
        $mock = Mockery::mock(JetStreamPublisher::class);

        if ($succeed) {
            $mock->shouldReceive('publish')->once()->andReturn([]);
        } else {
            $mock->shouldReceive('publish')->once()->andThrow(
                new \RuntimeException($errorMessage ?? 'publish failed')
            );
        }

        return $mock;
    }

    // -----------------------------------------------------------------------
    // Job config
    // -----------------------------------------------------------------------

    public function test_job_has_ten_tries(): void
    {
        $job = new PublishHiringOutboxEventJob('1');
        $this->assertSame(10, $job->tries);
    }

    public function test_outbox_event_id_is_string(): void
    {
        $job = new PublishHiringOutboxEventJob('42');
        $this->assertIsString($job->outboxEventId);
        $this->assertSame('42', $job->outboxEventId);
    }

    // -----------------------------------------------------------------------
    // Already published → skip
    // -----------------------------------------------------------------------

    public function test_already_published_event_is_skipped(): void
    {
        $event = $this->makeOutboxEvent(['published_at' => now()]);

        $publisher = Mockery::mock(JetStreamPublisher::class);
        $publisher->shouldNotReceive('publish');

        (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Successful publish
    // -----------------------------------------------------------------------

    public function test_successful_publish_sets_published_at(): void
    {
        $event     = $this->makeOutboxEvent();
        $publisher = $this->mockPublisher(succeed: true);

        (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);

        $event->refresh();
        $this->assertNotNull($event->published_at);
    }

    public function test_successful_publish_clears_last_error(): void
    {
        $event     = $this->makeOutboxEvent(['last_error' => 'previous error']);
        $publisher = $this->mockPublisher(succeed: true);

        (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);

        $event->refresh();
        $this->assertNull($event->last_error);
    }

    public function test_successful_publish_increments_attempts(): void
    {
        $event     = $this->makeOutboxEvent(['attempts' => 2]);
        $publisher = $this->mockPublisher(succeed: true);

        (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);

        $event->refresh();
        $this->assertSame(3, $event->attempts);
    }

    // -----------------------------------------------------------------------
    // Failed publish — exception propagates, failed() hook stores last_error
    // -----------------------------------------------------------------------

    public function test_failed_publish_propagates_exception(): void
    {
        $event     = $this->makeOutboxEvent();
        $publisher = $this->mockPublisher(succeed: false, errorMessage: 'connection lost');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection lost');

        (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);
    }

    public function test_failed_publish_increments_attempts(): void
    {
        $event     = $this->makeOutboxEvent();
        $publisher = $this->mockPublisher(succeed: false);

        try {
            (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);
        } catch (\Throwable) {
        }

        $event->refresh();
        $this->assertSame(1, $event->attempts);
    }

    public function test_failed_publish_does_not_set_published_at(): void
    {
        $event     = $this->makeOutboxEvent();
        $publisher = $this->mockPublisher(succeed: false);

        try {
            (new PublishHiringOutboxEventJob((string) $event->id))->handle($publisher);
        } catch (\Throwable) {
        }

        $event->refresh();
        $this->assertNull($event->published_at);
    }

    public function test_failed_hook_stores_last_error(): void
    {
        $event = $this->makeOutboxEvent();

        $job = new PublishHiringOutboxEventJob((string) $event->id);
        $job->failed(new \RuntimeException('nats timeout'));

        $event->refresh();
        $this->assertSame('nats timeout', $event->last_error);
    }

    // -----------------------------------------------------------------------
    // Idempotency: second call is a no-op once published_at is set
    // -----------------------------------------------------------------------

    public function test_job_is_idempotent_second_call_is_noop(): void
    {
        $event     = $this->makeOutboxEvent();
        $publisher = Mockery::mock(JetStreamPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturn([]);

        $job = new PublishHiringOutboxEventJob((string) $event->id);
        $job->handle($publisher);
        $job->handle($publisher); // second call: published_at set, should skip
    }
}
