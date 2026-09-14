<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * This is an API-only Laravel app (no Blade views -- see
 * routes/api.php and docs/php-conversion-plan.md), so the default
 * Laravel "/" example test doesn't apply; this replaces it with a
 * check of the one endpoint every deployment should always answer,
 * mirroring backend/app/main.py's GET /api/health.
 */
class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)->assertJson(['status' => 'ok']);
    }
}
