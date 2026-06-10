<?php

namespace Tests\Feature\HiringEvents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SafetyTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // F: No AuthOutboxEvent class created
    // -----------------------------------------------------------------------

    public function test_no_auth_outbox_event_class_exists(): void
    {
        $this->assertFalse(class_exists('App\Models\AuthOutboxEvent'));
        $this->assertFalse(class_exists('App\Services\AuthOutboxEvent'));
    }

    // -----------------------------------------------------------------------
    // F: No auth_outbox_events table created
    // -----------------------------------------------------------------------

    public function test_no_auth_outbox_events_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('auth_outbox_events'));
    }

    // -----------------------------------------------------------------------
    // F: NATS_HIRING_STREAM not used in nats.php
    // -----------------------------------------------------------------------

    public function test_nats_hiring_stream_env_key_not_used_in_config(): void
    {
        $natsPhp = file_get_contents(config_path('nats.php'));

        $this->assertStringNotContainsString('NATS_HIRING_STREAM', $natsPhp);
        $this->assertStringContainsString('NATS_HIRING_PLATFORM_STREAM', $natsPhp);
    }

    // -----------------------------------------------------------------------
    // F: No Policies or Gates in new files
    // -----------------------------------------------------------------------

    public function test_hiring_event_factory_has_no_policy_or_gate(): void
    {
        $contents = file_get_contents(app_path('Services/HiringEvents/HiringEventFactory.php'));

        $this->assertStringNotContainsString('Gate::', $contents);
        $this->assertStringNotContainsString('authorize(', $contents);
        $this->assertStringNotContainsString('Policy', $contents);
    }

    public function test_hiring_outbox_service_has_no_policy_or_gate(): void
    {
        $contents = file_get_contents(app_path('Services/HiringEvents/HiringOutboxService.php'));

        $this->assertStringNotContainsString('Gate::', $contents);
        $this->assertStringNotContainsString('authorize(', $contents);
    }

    // -----------------------------------------------------------------------
    // F: JetStreamConsumer was not modified
    // -----------------------------------------------------------------------

    public function test_jetstream_consumer_does_not_reference_hiring_event_factory(): void
    {
        $contents = file_get_contents(app_path('Services/Nats/JetStreamConsumer.php'));

        $this->assertStringNotContainsString('HiringEventFactory', $contents);
        $this->assertStringNotContainsString('HiringOutboxService', $contents);
        $this->assertStringNotContainsString('PublishHiringOutboxEventJob', $contents);
    }

    public function test_jetstream_consumer_subject_allow_prefixes_unchanged(): void
    {
        $contents = file_get_contents(app_path('Services/Nats/JetStreamConsumer.php'));

        // The inbound allowlist must still only include auth.v1.
        $this->assertStringContainsString("'auth.v1.'", $contents);
    }

    // -----------------------------------------------------------------------
    // F: Outbound publish configuration is correct
    // -----------------------------------------------------------------------

    public function test_outbound_stream_uses_hiring_platform_events_default(): void
    {
        $this->assertSame('HIRING_PLATFORM_EVENTS', config('nats.jetstream.stream'));
    }

    public function test_publish_allowed_subjects_contains_hiring_v1(): void
    {
        $subjects = config('nats.jetstream.subjects');

        $this->assertContains('hiring.v1.>', $subjects);
    }

    // -----------------------------------------------------------------------
    // F: Existing outbox table / model intact
    // -----------------------------------------------------------------------

    public function test_outbox_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('outbox_events'));
    }

    public function test_outbox_event_model_is_intact(): void
    {
        $this->assertTrue(class_exists('App\Models\OutboxEvent'));
    }
}
