<?php

namespace Tests\Feature\Events;

use App\Models\OutboxEvent;
use App\Services\Events\EventBusPublisher;
use App\Services\Events\FakeEventBusPublisher;
use App\Services\Events\OutboxPublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxPublisherTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEvent(array $overrides = []): OutboxEvent
    {
        return OutboxEvent::create(array_merge([
            'event_id'   => Str::uuid()->toString(),
            'event_type' => 'hiring.test.event',
            'subject'    => 'hiring.test.event',
            'payload'    => ['foo' => 'bar'],
            'status'     => 'pending',
            'attempts'   => 0,
        ], $overrides));
    }

    private function service(FakeEventBusPublisher $publisher): OutboxPublisherService
    {
        return new OutboxPublisherService($publisher);
    }

    // -----------------------------------------------------------------------
    // Success path
    // -----------------------------------------------------------------------

    public function test_pending_event_is_published_and_marked_published(): void
    {
        $event     = $this->makeEvent();
        $publisher = new FakeEventBusPublisher();

        $result = $this->service($publisher)->run();

        $this->assertSame(1, $result['published']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['retried']);

        $event->refresh();
        $this->assertSame('published', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
    }

    public function test_published_at_is_set_on_success(): void
    {
        $event     = $this->makeEvent();
        $publisher = new FakeEventBusPublisher();

        $this->service($publisher)->run();

        $event->refresh();
        $this->assertNotNull($event->published_at);
    }

    public function test_failed_at_and_last_error_are_cleared_on_success_after_prior_failure(): void
    {
        $event = $this->makeEvent([
            'attempts'   => 2,
            'last_error' => 'previous connection error',
            'failed_at'  => now()->subMinutes(5),
        ]);

        $publisher = new FakeEventBusPublisher();
        $this->service($publisher)->run();

        $event->refresh();
        $this->assertSame('published', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
        $this->assertNull($event->failed_at);
        $this->assertNull($event->last_error);
    }

    // -----------------------------------------------------------------------
    // Failure path
    // -----------------------------------------------------------------------

    public function test_publisher_failure_increments_attempts_and_stores_last_error(): void
    {
        $event     = $this->makeEvent();
        $publisher = new FakeEventBusPublisher();
        $publisher->makeFail('connection refused');

        $this->service($publisher)->run(maxAttempts: 5);

        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertSame('connection refused', $event->last_error);
    }

    public function test_failed_publish_below_max_remains_pending_with_future_available_at(): void
    {
        $event     = $this->makeEvent();
        $publisher = new FakeEventBusPublisher();
        $publisher->makeFail();

        $this->service($publisher)->run(maxAttempts: 5);

        $event->refresh();
        $this->assertSame('pending', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
        $this->assertNotNull($event->available_at);
        $this->assertTrue($event->available_at->isFuture());
    }

    public function test_failed_publish_at_max_attempts_marks_event_failed_with_failed_at(): void
    {
        $event = $this->makeEvent(['attempts' => 4]);

        $publisher = new FakeEventBusPublisher();
        $publisher->makeFail('final failure');

        $result = $this->service($publisher)->run(maxAttempts: 5);

        $this->assertSame(0, $result['published']);
        $this->assertSame(1, $result['failed']);

        $event->refresh();
        $this->assertSame('failed', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
        $this->assertNotNull($event->failed_at);
        $this->assertSame('final failure', $event->last_error);
    }

    // -----------------------------------------------------------------------
    // Future available_at exclusion
    // -----------------------------------------------------------------------

    public function test_event_with_future_available_at_is_not_published(): void
    {
        $event     = $this->makeEvent(['available_at' => now()->addHour()]);
        $publisher = new FakeEventBusPublisher();

        $result = $this->service($publisher)->run();

        $this->assertSame(0, $result['published']);
        $this->assertEmpty($publisher->published());

        $event->refresh();
        $this->assertSame('pending', $event->status instanceof \BackedEnum ? $event->status->value : $event->status);
    }

    // -----------------------------------------------------------------------
    // Artisan command
    // -----------------------------------------------------------------------

    public function test_command_runs_successfully_and_outputs_summary(): void
    {
        $this->makeEvent();

        $publisher = new FakeEventBusPublisher();
        $this->app->instance(EventBusPublisher::class, $publisher);

        $this->artisan('events:publish-outbox')
            ->expectsOutputToContain('Published: 1')
            ->expectsOutputToContain('Failed:    0')
            ->expectsOutputToContain('Retried:   0')
            ->assertExitCode(0);
    }

    public function test_command_accepts_limit_and_max_attempts_options(): void
    {
        $publisher = new FakeEventBusPublisher();
        $this->app->instance(EventBusPublisher::class, $publisher);

        $this->artisan('events:publish-outbox', ['--limit' => 10, '--max-attempts' => 3])
            ->expectsOutputToContain('Published: 0')
            ->assertExitCode(0);
    }
}
