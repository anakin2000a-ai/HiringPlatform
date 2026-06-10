<?php

namespace Tests\Feature\HiringEvents;

use App\Services\HiringEvents\HiringEventFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HiringEventFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function factory(): HiringEventFactory
    {
        return new HiringEventFactory();
    }

    // -----------------------------------------------------------------------
    // CloudEvents envelope shape
    // -----------------------------------------------------------------------

    public function test_specversion_is_1_0(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        $this->assertSame('1.0', $env['specversion']);
    }

    public function test_no_top_level_version_key(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        $this->assertArrayNotHasKey('version', $env);
    }

    public function test_type_equals_subject(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', ['foo' => 'bar']);

        $this->assertSame('hiring.v1.application.hired', $env['type']);
        $this->assertSame('hiring.v1.application.hired', $env['subject']);
    }

    public function test_source_is_hiring_platform(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        $this->assertSame('hiring-platform', $env['source']);
    }

    public function test_datacontenttype_is_application_json(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        $this->assertSame('application/json', $env['datacontenttype']);
    }

    public function test_id_is_ulid_format(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        // ULID: 26 chars, uppercase base32
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $env['id']);
    }

    public function test_two_calls_produce_different_ids(): void
    {
        $factory = $this->factory();

        $a = $factory->make('hiring.v1.application.hired', []);
        $b = $factory->make('hiring.v1.application.hired', []);

        $this->assertNotSame($a['id'], $b['id']);
    }

    public function test_time_field_is_present_and_non_empty(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        $this->assertArrayHasKey('time', $env);
        $this->assertNotEmpty($env['time']);
    }

    public function test_data_is_passed_through(): void
    {
        $data = ['application_id' => 99, 'status' => 'hired'];

        $env = $this->factory()->make('hiring.v1.application.hired', $data);

        $this->assertSame($data, $env['data']);
    }

    // -----------------------------------------------------------------------
    // Meta block
    // -----------------------------------------------------------------------

    public function test_meta_block_is_present(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', []);

        $this->assertArrayHasKey('meta', $env);
        $this->assertIsArray($env['meta']);
    }

    public function test_meta_contains_correlation_id_when_no_request(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', [], null);

        $this->assertArrayHasKey('correlation_id', $env['meta']);
        $this->assertNotEmpty($env['meta']['correlation_id']);
    }

    public function test_meta_actor_type_is_service_client_when_no_request(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', [], null);

        $this->assertSame('service_client', $env['meta']['actor_type']);
    }

    public function test_meta_actor_user_id_is_null_when_no_request(): void
    {
        $env = $this->factory()->make('hiring.v1.application.hired', [], null);

        $this->assertNull($env['meta']['actor_user_id']);
    }

    public function test_meta_overrides_are_applied(): void
    {
        $env = $this->factory()->make(
            'hiring.v1.application.hired',
            [],
            null,
            ['correlation_id' => 'my-correlation-id']
        );

        $this->assertSame('my-correlation-id', $env['meta']['correlation_id']);
    }
}
