<?php

namespace Tests\Feature\Events;

use App\Models\Applicant;
use App\Models\FranchiseAccount;
use App\Models\InboxEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\Events\InboxEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class InboxEventProcessorTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function processor(): InboxEventProcessor
    {
        return new InboxEventProcessor();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides = []): array
    {
        return array_merge([
            'event_id'    => Str::uuid()->toString(),
            'event_type'  => 'stores.store.updated',
            'occurred_at' => '2026-01-01T00:00:00Z',
            'source'      => 'test-service',
            'version'     => 1,
            'data'        => [],
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Basic processing
    // -----------------------------------------------------------------------

    public function test_new_event_creates_inbox_row_and_marks_processed(): void
    {
        $envelope = $this->envelope();

        $result = $this->processor()->process('stores.store.updated', $envelope);

        $this->assertSame('processed', $result->status);
        $this->assertNotNull($result->processed_at);
        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'processed',
        ]);
    }

    public function test_duplicate_processed_event_id_is_skipped_idempotently(): void
    {
        $envelope = $this->envelope();

        $this->processor()->process('stores.store.updated', $envelope);
        $result2 = $this->processor()->process('stores.store.updated', $envelope);

        $this->assertSame(1, InboxEvent::where('event_id', $envelope['event_id'])->count());
        $this->assertSame('processed', $result2->status);
    }

    public function test_missing_event_id_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_id');

        $this->processor()->process('stores.store.updated', ['data' => []]);
    }

    // -----------------------------------------------------------------------
    // Subject handlers
    // -----------------------------------------------------------------------

    public function test_store_updated_updates_local_store_when_found(): void
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create(['store_name' => 'Old Name']);

        $envelope = $this->envelope([
            'event_type' => 'stores.store.updated',
            'data'       => ['id' => $store->id, 'store_name' => 'New Name'],
        ]);

        $this->processor()->process('stores.store.updated', $envelope);

        $this->assertDatabaseHas('stores', [
            'id'         => $store->id,
            'store_name' => 'New Name',
        ]);
    }

    public function test_store_updated_is_no_op_when_store_not_found(): void
    {
        $envelope = $this->envelope([
            'data' => ['id' => 999999, 'store_name' => 'Ghost Store'],
        ]);

        $result = $this->processor()->process('stores.store.updated', $envelope);

        $this->assertSame('processed', $result->status);
    }

    public function test_applicant_updated_updates_local_applicant_when_found(): void
    {
        $applicant = Applicant::factory()->create(['first_name' => 'Old', 'last_name' => 'Name']);

        $envelope = $this->envelope([
            'event_type' => 'applicants.applicant.updated',
            'data'       => ['id' => $applicant->id, 'first_name' => 'New', 'last_name' => 'Person'],
        ]);

        $this->processor()->process('applicants.applicant.updated', $envelope);

        $this->assertDatabaseHas('applicants', [
            'id'         => $applicant->id,
            'first_name' => 'New',
            'last_name'  => 'Person',
        ]);
    }

    public function test_unknown_subject_is_safely_processed_as_noop(): void
    {
        $envelope = $this->envelope([
            'event_type' => 'unknown.thing.happened',
        ]);

        $result = $this->processor()->process('unknown.thing.happened', $envelope);

        $this->assertSame('processed', $result->status);
        $this->assertDatabaseHas('inbox_events', [
            'event_id' => $envelope['event_id'],
            'status'   => 'processed',
        ]);
    }

    public function test_employee_created_is_processed_as_noop(): void
    {
        $envelope = $this->envelope([
            'event_type' => 'employees.employee.created',
            'data'       => ['id' => 1, 'name' => 'Alice'],
        ]);

        $result = $this->processor()->process('employees.employee.created', $envelope);

        $this->assertSame('processed', $result->status);
    }

    // -----------------------------------------------------------------------
    // Failure path
    // -----------------------------------------------------------------------

    public function test_failed_handler_marks_event_failed_with_attempts_and_last_error(): void
    {
        $envelope = $this->envelope();

        $processor = new class extends InboxEventProcessor {
            protected function dispatch(string $subject, array $envelope): void
            {
                throw new \RuntimeException('Simulated handler failure');
            }
        };

        try {
            $processor->process('stores.store.updated', $envelope);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // expected — exception is re-thrown after recording the failure
        }

        $this->assertDatabaseHas('inbox_events', [
            'event_id'   => $envelope['event_id'],
            'status'     => 'failed',
            'last_error' => 'Simulated handler failure',
        ]);

        $inbox = InboxEvent::where('event_id', $envelope['event_id'])->first();
        $this->assertSame(1, $inbox->attempts);
        $this->assertNotNull($inbox->failed_at);
    }
}
