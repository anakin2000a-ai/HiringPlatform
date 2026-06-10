<?php

namespace Tests\Feature\HiringEvents;

use App\Jobs\PublishHiringOutboxEventJob;
use App\Models\OutboxEvent;
use App\Services\HiringEvents\HiringOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class HiringOutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnvelope(string $subject = 'hiring.v1.application.hired'): array
    {
        return [
            'specversion'     => '1.0',
            'id'              => (string) Str::ulid(),
            'type'            => $subject,
            'source'          => 'hiring-platform',
            'subject'         => $subject,
            'time'            => now()->utc()->toIso8601String(),
            'datacontenttype' => 'application/json',
            'data'            => ['application_id' => 1],
            'meta'            => [],
        ];
    }

    private function service(): HiringOutboxService
    {
        return new HiringOutboxService();
    }

    // -----------------------------------------------------------------------
    // record() persists to outbox_events
    // -----------------------------------------------------------------------

    public function test_record_creates_outbox_row(): void
    {
        $envelope = $this->makeEnvelope();

        $this->service()->record($envelope['subject'], $envelope);

        $this->assertDatabaseHas('outbox_events', [
            'event_id'   => $envelope['id'],
            'event_type' => 'hiring.v1.application.hired',
            'subject'    => 'hiring.v1.application.hired',
            'status'     => 'pending',
            'attempts'   => 0,
        ]);
    }

    public function test_record_stores_full_envelope_as_payload(): void
    {
        $envelope = $this->makeEnvelope();

        $this->service()->record($envelope['subject'], $envelope);

        $row = OutboxEvent::where('event_id', $envelope['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($envelope['id'], $row->payload['id']);
        $this->assertSame('hiring-platform', $row->payload['source']);
        $this->assertSame('1.0', $row->payload['specversion']);
    }

    public function test_record_returns_outbox_event_model(): void
    {
        $envelope = $this->makeEnvelope();

        $result = $this->service()->record($envelope['subject'], $envelope);

        $this->assertInstanceOf(OutboxEvent::class, $result);
        $this->assertSame($envelope['id'], $result->event_id);
    }

    public function test_record_uses_payload_id_as_event_id(): void
    {
        $envelope = $this->makeEnvelope();

        $row = $this->service()->record($envelope['subject'], $envelope);

        $this->assertSame($envelope['id'], $row->event_id);
    }

    // -----------------------------------------------------------------------
    // record() does NOT dispatch jobs — that is ApplicationStageService's job
    // -----------------------------------------------------------------------

    public function test_record_does_not_dispatch_publish_job(): void
    {
        Queue::fake();

        $envelope = $this->makeEnvelope();

        $this->service()->record($envelope['subject'], $envelope);

        Queue::assertNotPushed(PublishHiringOutboxEventJob::class);
    }

    // -----------------------------------------------------------------------
    // Rollback leaves no row
    // -----------------------------------------------------------------------

    public function test_rollback_does_not_leave_outbox_row(): void
    {
        $envelope = $this->makeEnvelope();

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($envelope): void {
                $this->service()->record($envelope['subject'], $envelope);
                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('outbox_events', ['event_id' => $envelope['id']]);
    }
}
