<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ok',
                    'service' => 'hiring-workflow-api',
                    'version' => 'v1',
                ],
            ]);
    }

    public function test_health_endpoint_includes_database_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'service', 'version', 'database'],
            ]);
    }

    public function test_health_endpoint_is_publicly_accessible(): void
    {
        // No auth token provided
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
    }
}
